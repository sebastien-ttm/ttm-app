<?php

namespace App\Service\HelloAsso;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API HelloAsso v5 :
 *  - lecture des paiements faits sur les formulaires de l'association (boutique, adhésion, billetterie…), pour le
 *    rapprochement des événements liés à un formulaire (fetchPaidItems) ;
 *  - HelloAsso Checkout : demander un paiement préparé par l'appli, adhérent déjà renseigné (createCheckoutIntent), et
 *    lire son état (getCheckoutIntent).
 *
 * Authentification OAuth2 « client credentials » (HELLOASSO_CLIENT_ID / HELLOASSO_CLIENT_SECRET, client API créé
 * dans le back-office HelloAsso avec les droits AccessTransactions — lecture — et Checkout — paiements de l'appli). Le
 * jeton d'accès (≈ 30 min) et le jeton de rafraîchissement (30 jours) sont gardés en cache : on rafraîchit plutôt que de
 * redemander un jeton à chaque appel, comme le recommande HelloAsso. Le cache est propre à l'environnement (adresse de
 * l'API + identifiant) : passer du bac à sable à la production ne réutilise pas un jeton de l'autre. Sans identifiants,
 * le client est inactif (isConfigured() = false) ; Checkout demande en plus le slug de l'association
 * (HELLOASSO_ORGANIZATION_SLUG, isCheckoutConfigured()).
 */
class HelloAssoClient
{
    private const ITEMS_TTL = 300;
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 50;

    /** Segment de l'URL publique d'un formulaire → formType de l'API. */
    private const URL_SEGMENTS = [
        'evenements' => 'Event',
        'adhesions' => 'Membership',
        'boutiques' => 'Shop',
        'formulaires' => 'PaymentForm',
        'paiements' => 'PaymentForm',
        'collectes' => 'CrowdFunding',
    ];

    public const FORM_TYPE_LABELS = [
        'Event' => 'Billetterie',
        'Membership' => 'Adhésion',
        'Shop' => 'Boutique',
        'PaymentForm' => 'Formulaire de paiement',
        'CrowdFunding' => 'Collecte',
        'Donation' => 'Dons',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiBaseUrl,
        private readonly string $organizationSlug = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->clientId) !== '' && trim($this->clientSecret) !== '';
    }

    /** Les paiements de l'appli (Checkout) demandent aussi le slug de l'association. */
    public function isCheckoutConfigured(): bool
    {
        return $this->isConfigured() && trim($this->organizationSlug) !== '';
    }

    /** Vrai quand l'API pointe sur le bac à sable HelloAsso (aucun vrai paiement). */
    public function isSandbox(): bool
    {
        return str_contains($this->apiBaseUrl, 'sandbox');
    }

    /**
     * Décompose l'URL publique d'un formulaire HelloAsso :
     * https://www.helloasso.com/associations/{organisation}/{evenements|adhesions|boutiques|formulaires…}/{formulaire}
     *
     * @return array{organizationSlug: string, formType: string, formSlug: string}|null
     */
    public static function parseFormUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $path = (string) parse_url(trim($url), \PHP_URL_PATH);
        $host = (string) parse_url(trim($url), \PHP_URL_HOST);
        if (!str_contains($host, 'helloasso')) {
            return null;
        }
        $parts = array_values(array_filter(explode('/', $path), static fn (string $p) => $p !== ''));
        $i = array_search('associations', $parts, true);
        if ($i === false || !isset($parts[$i + 3])) {
            return null;
        }
        $formType = self::URL_SEGMENTS[strtolower($parts[$i + 2])] ?? null;
        if ($formType === null) {
            return null;
        }
        return ['organizationSlug' => $parts[$i + 1], 'formType' => $formType, 'formSlug' => $parts[$i + 3]];
    }

    /**
     * Ventes valides d'un formulaire (états Processed = payé, Registered = enregistré par l'association), mises en
     * cache 5 minutes ; $refresh force la relecture.
     *
     * @return list<HelloAssoPayment>
     * @throws HelloAssoException
     */
    public function fetchPaidItems(string $organizationSlug, string $formType, string $formSlug, bool $refresh = false): array
    {
        if (!$this->isConfigured()) {
            throw new HelloAssoException('HelloAsso n\'est pas configuré (identifiants API manquants).');
        }
        $key = 'helloasso.items.'.md5($organizationSlug.'|'.$formType.'|'.$formSlug);
        if ($refresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($organizationSlug, $formType, $formSlug): array {
            $item->expiresAfter(self::ITEMS_TTL);
            return $this->loadItems($organizationSlug, $formType, $formSlug);
        });
    }

    /**
     * Demande de paiement HelloAsso Checkout (POST /v5/organizations/{slug}/checkout-intents) : montants en centimes,
     * intitulé, adresses de retour et — facultatifs mais recommandés — le payeur (le formulaire HelloAsso s'ouvre
     * prérempli) et des métadonnées renvoyées telles quelles dans les notifications et à la relecture.
     *
     * @param array<string, mixed> $body  totalAmount, initialAmount, itemName, backUrl, errorUrl, returnUrl, containsDonation, payer?, metadata?
     * @return array{id: int, redirectUrl: ?string} redirectUrl : page de paiement, valable 15 minutes
     * @throws HelloAssoException
     */
    public function createCheckoutIntent(array $body): array
    {
        $this->assertCheckoutConfigured();
        $data = $this->request('POST', $this->checkoutUrl(), $body, checkout: true);
        $id = $data['id'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            $this->logger->warning('HelloAsso : réponse de paiement sans identifiant', ['response' => $data]);
            throw new HelloAssoException('HelloAsso n\'a pas confirmé la demande de paiement, réessayez plus tard.');
        }

        return ['id' => (int) $id, 'redirectUrl' => is_string($data['redirectUrl'] ?? null) ? $data['redirectUrl'] : null];
    }

    /**
     * État d'une demande de paiement : la clé « order » n'est présente (non vide) que si le paiement a abouti. C'est la
     * seule source fiable : l'adresse de retour comme les notifications peuvent être falsifiées, cet appel authentifié non.
     *
     * @return array<string, mixed>
     * @throws HelloAssoException
     */
    public function getCheckoutIntent(int $intentId): array
    {
        $this->assertCheckoutConfigured();

        return $this->request('GET', $this->checkoutUrl().'/'.$intentId, null, checkout: true);
    }

    private function assertCheckoutConfigured(): void
    {
        if (!$this->isCheckoutConfigured()) {
            throw new HelloAssoException('Le paiement HelloAsso n\'est pas configuré sur le serveur (identifiants API et identifiant de l\'association).');
        }
    }

    private function checkoutUrl(): string
    {
        return sprintf('%s/v5/organizations/%s/checkout-intents', rtrim($this->apiBaseUrl, '/'), rawurlencode(trim($this->organizationSlug)));
    }

    /** @return list<HelloAssoPayment> */
    private function loadItems(string $organizationSlug, string $formType, string $formSlug): array
    {
        $url = sprintf(
            '%s/v5/organizations/%s/forms/%s/%s/items',
            rtrim($this->apiBaseUrl, '/'),
            rawurlencode($organizationSlug),
            rawurlencode($formType),
            rawurlencode($formSlug),
        );

        $payments = [];
        $continuation = null;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            // itemStates est un tableau : paramètre répété (itemStates=Processed&itemStates=Registered).
            $query = 'pageSize='.self::PAGE_SIZE.'&itemStates=Processed&itemStates=Registered&withDetails=false'
                .($continuation !== null ? '&continuationToken='.rawurlencode($continuation) : '&pageIndex=1');
            $data = $this->request('GET', $url.'?'.$query);

            $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $payments[] = HelloAssoPayment::fromApi($row);
                }
            }
            $next = $data['pagination']['continuationToken'] ?? null;
            if ($rows === [] || count($rows) < self::PAGE_SIZE || !is_string($next) || $next === '' || $next === $continuation) {
                break;
            }
            $continuation = $next;
        }

        return $payments;
    }

    /**
     * @param array<string, mixed>|null $json  corps JSON (POST)
     * @param bool $checkout  appel Checkout : messages d'erreur adaptés (droit « Checkout », association non vérifiée…)
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $json = null, bool $checkout = false, bool $retried = false): array
    {
        try {
            $options = [
                'headers' => ['Authorization' => 'Bearer '.$this->accessToken(), 'Accept' => 'application/json'],
                'timeout' => 20,
            ];
            if ($json !== null) {
                $options['json'] = $json;
            }
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            if ($status === 401 && !$retried) {
                // Jeton refusé (révoqué, horloge…) : on repart d'un jeton neuf, une seule fois.
                $this->cache->delete($this->tokenKey());
                return $this->request($method, $url, $json, $checkout, true);
            }
            if ($status === 403) {
                throw new HelloAssoException($checkout
                    ? 'HelloAsso refuse l\'accès : le client API doit avoir le droit « Checkout » (créer un paiement).'
                    : 'HelloAsso refuse l\'accès : le client API doit avoir le droit « AccessTransactions » sur ce formulaire.');
            }
            if ($status === 404) {
                throw new HelloAssoException($checkout
                    ? 'HelloAsso ne retrouve pas l\'association : vérifiez HELLOASSO_ORGANIZATION_SLUG.'
                    : 'Formulaire HelloAsso introuvable : vérifiez le lien saisi sur l\'événement.');
            }
            if ($status === 409) {
                throw new HelloAssoException('HelloAsso ne peut pas encaisser pour cette association pour le moment (association non vérifiée ?).');
            }
            if ($status >= 400) {
                $this->logger->warning('HelloAsso : requête refusée', ['url' => $url, 'status' => $status, 'body' => mb_substr($response->getContent(false), 0, 1500)]);
                throw new HelloAssoException(sprintf('HelloAsso a répondu une erreur %d.', $status));
            }
            return $response->toArray(false);
        } catch (HelloAssoException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('HelloAsso : appel en échec', ['url' => $url, 'error' => $e->getMessage()]);
            throw new HelloAssoException('HelloAsso est injoignable pour le moment, réessayez plus tard.', 0, $e);
        }
    }

    private function tokenKey(): string
    {
        return 'helloasso.token.'.md5($this->apiBaseUrl.'|'.$this->clientId);
    }

    /** Jeton d'accès valide : en cache, rafraîchi, ou obtenu par client credentials. */
    private function accessToken(): string
    {
        /** @var array{access: string, refresh: ?string, expiresAt: int, refreshExpiresAt: int} $token */
        $token = $this->cache->get($this->tokenKey(), function (ItemInterface $item): array {
            $item->expiresAfter(29 * 86400);
            return $this->requestToken(['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
        });

        if ($token['expiresAt'] > time() + 60) {
            return $token['access'];
        }

        $fresh = null;
        if ($token['refresh'] !== null && $token['refreshExpiresAt'] > time() + 60) {
            try {
                $fresh = $this->requestToken(['grant_type' => 'refresh_token', 'client_id' => $this->clientId, 'refresh_token' => $token['refresh']]);
            } catch (HelloAssoException) {
                $fresh = null;
            }
        }
        $fresh ??= $this->requestToken(['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);

        $this->cache->delete($this->tokenKey());
        $this->cache->get($this->tokenKey(), static function (ItemInterface $item) use ($fresh): array {
            $item->expiresAfter(29 * 86400);
            return $fresh;
        });

        return $fresh['access'];
    }

    /**
     * @param array<string, string> $body
     * @return array{access: string, refresh: ?string, expiresAt: int, refreshExpiresAt: int}
     */
    private function requestToken(array $body): array
    {
        try {
            $response = $this->http->request('POST', rtrim($this->apiBaseUrl, '/').'/oauth2/token', [
                'body' => $body,
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('HelloAsso : jeton en échec', ['error' => $e->getMessage()]);
            throw new HelloAssoException('HelloAsso est injoignable pour le moment, réessayez plus tard.', 0, $e);
        }
        if ($status >= 400 || !is_string($data['access_token'] ?? null)) {
            $this->logger->warning('HelloAsso : jeton refusé', ['status' => $status, 'grant' => $body['grant_type']]);
            throw new HelloAssoException('HelloAsso refuse les identifiants API (HELLOASSO_CLIENT_ID / HELLOASSO_CLIENT_SECRET).');
        }

        return [
            'access' => $data['access_token'],
            'refresh' => is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            'expiresAt' => time() + (int) ($data['expires_in'] ?? 1700),
            'refreshExpiresAt' => time() + 29 * 86400,
        ];
    }
}
