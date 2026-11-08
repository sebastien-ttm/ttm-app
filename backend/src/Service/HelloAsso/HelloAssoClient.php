<?php

namespace App\Service\HelloAsso;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API HelloAsso v5, en lecture seule, pour vérifier les paiements faits sur les formulaires de
 * l'association (boutique, adhésion, billetterie…).
 *
 * Authentification OAuth2 « client credentials » (HELLOASSO_CLIENT_ID / HELLOASSO_CLIENT_SECRET, client API créé
 * dans le back-office HelloAsso avec le droit AccessTransactions). Le jeton d'accès (≈ 30 min) et le jeton de
 * rafraîchissement (30 jours) sont gardés en cache : on rafraîchit plutôt que de redemander un jeton à chaque
 * appel, comme le recommande HelloAsso. Sans identifiants, le client est inactif (isConfigured() = false).
 */
class HelloAssoClient
{
    private const TOKEN_CACHE_KEY = 'helloasso.token';
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
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->clientId) !== '' && trim($this->clientSecret) !== '';
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
            $data = $this->get($url.'?'.$query);

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

    /** @return array<string, mixed> */
    private function get(string $url, bool $retried = false): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer '.$this->accessToken(), 'Accept' => 'application/json'],
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 && !$retried) {
                // Jeton refusé (révoqué, horloge…) : on repart d'un jeton neuf, une seule fois.
                $this->cache->delete(self::TOKEN_CACHE_KEY);
                return $this->get($url, true);
            }
            if ($status === 403) {
                throw new HelloAssoException('HelloAsso refuse l\'accès : le client API doit avoir le droit « AccessTransactions » sur ce formulaire.');
            }
            if ($status === 404) {
                throw new HelloAssoException('Formulaire HelloAsso introuvable : vérifiez le lien saisi sur l\'événement.');
            }
            if ($status >= 400) {
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

    /** Jeton d'accès valide : en cache, rafraîchi, ou obtenu par client credentials. */
    private function accessToken(): string
    {
        /** @var array{access: string, refresh: ?string, expiresAt: int, refreshExpiresAt: int} $token */
        $token = $this->cache->get(self::TOKEN_CACHE_KEY, function (ItemInterface $item): array {
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

        $this->cache->delete(self::TOKEN_CACHE_KEY);
        $this->cache->get(self::TOKEN_CACHE_KEY, static function (ItemInterface $item) use ($fresh): array {
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
