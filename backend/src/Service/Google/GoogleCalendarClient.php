<?php

namespace App\Service\Google;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client minimal de l'API Google Agenda v3 : créer et supprimer un événement. Sans dépendance Composer (pas de
 * google/apiclient) : le jeton d'accès s'obtient avec un « compte de service » (clé JSON) qui agit au nom d'un
 * utilisateur du Google Workspace du club (délégation à l'échelle du domaine), seule façon d'inviter des participants.
 *
 * Authentification : JWT signé RS256 avec la clé privée du compte de service (`iss` = client_email, `sub` =
 * l'utilisateur usurpé, scope calendar.events), échangé contre un jeton d'accès valable une heure — gardé en cache.
 *
 * Configuration (voir docs/GOOGLE-CALENDAR.md) :
 *  - GOOGLE_CALENDAR_CREDENTIALS : chemin du fichier JSON du compte de service (absolu, ou relatif à la racine du projet) ;
 *  - GOOGLE_CALENDAR_IMPERSONATE : adresse de l'utilisateur du Workspace au nom duquel les événements sont créés
 *    (l'organisateur de la réunion) ;
 *  - GOOGLE_CALENDAR_ID : agenda cible (« primary » = l'agenda de l'utilisateur ci-dessus, ou l'identifiant d'un agenda partagé).
 * Sans cela, le client est inactif (isConfigured() = false) et rien n'est envoyé.
 */
class GoogleCalendarClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly string $credentialsPath,
        private readonly string $impersonate,
        private readonly string $calendarId,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->impersonate) !== '' && $this->credentialsFile() !== null;
    }

    /**
     * Crée un événement (Google envoie les invitations aux participants : sendUpdates=all).
     *
     * @param array<string, mixed> $event corps de l'événement (summary, start, end, attendees…)
     *
     * @return array{id: string, htmlLink: ?string}
     *
     * @throws GoogleCalendarException
     */
    public function createEvent(array $event): array
    {
        $data = $this->request('POST', $this->eventsPath(), ['sendUpdates' => 'all'], $event);
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            throw new GoogleCalendarException('Google Agenda n\'a pas renvoyé d\'identifiant pour l\'événement créé.');
        }

        return ['id' => $id, 'htmlLink' => isset($data['htmlLink']) ? (string) $data['htmlLink'] : null];
    }

    /**
     * Supprime un événement (les participants sont prévenus de l'annulation). Un événement déjà supprimé ou introuvable
     * n'est pas une erreur.
     *
     * @throws GoogleCalendarException
     */
    public function deleteEvent(string $eventId): void
    {
        try {
            $this->request('DELETE', $this->eventsPath().'/'.rawurlencode($eventId), ['sendUpdates' => 'all']);
        } catch (GoogleCalendarException $e) {
            if ($e->getCode() === 404 || $e->getCode() === 410) {
                return;
            }
            throw $e;
        }
    }

    private function eventsPath(): string
    {
        $calendar = trim($this->calendarId) !== '' ? trim($this->calendarId) : 'primary';

        return '/calendars/'.rawurlencode($calendar).'/events';
    }

    /**
     * @param array<string, scalar>     $query
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     *
     * @throws GoogleCalendarException
     */
    private function request(string $method, string $path, array $query = [], ?array $json = null, bool $retried = false): array
    {
        if (!$this->isConfigured()) {
            throw new GoogleCalendarException('Google Agenda n\'est pas configuré (identifiants du compte de service ou utilisateur à usurper manquants).');
        }

        try {
            $options = [
                'headers' => ['Authorization' => 'Bearer '.$this->accessToken(), 'Accept' => 'application/json'],
                'query' => $query,
                'timeout' => 20,
            ];
            if ($json !== null) {
                $options['json'] = $json;
            }
            $response = $this->http->request($method, self::API_BASE.$path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (GoogleCalendarException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Google Agenda injoignable : '.$e->getMessage());
            throw new GoogleCalendarException('Google Agenda est injoignable pour le moment, réessayez plus tard.', 0, $e);
        }

        // Jeton refusé (révoqué, horloge…) : on le jette et on retente une fois avec un jeton neuf.
        if ($status === 401 && !$retried) {
            $this->cache->delete($this->tokenCacheKey());
            return $this->request($method, $path, $query, $json, true);
        }

        $data = $content !== '' ? json_decode($content, true) : [];
        $data = is_array($data) ? $data : [];
        if ($status >= 400) {
            $detail = (string) ($data['error']['message'] ?? '');
            $this->logger->warning(sprintf('Google Agenda : %s %s -> %d %s', $method, $path, $status, $detail));
            throw new GoogleCalendarException(
                sprintf('Google Agenda a répondu une erreur %d%s.', $status, $detail !== '' ? ' : '.$detail : ''),
                $status,
            );
        }

        return $data;
    }

    private function accessToken(): string
    {
        return $this->cache->get($this->tokenCacheKey(), function (ItemInterface $item): string {
            [$token, $lifetime] = $this->requestToken();
            // Renouvelé 2 minutes avant l'expiration réelle.
            $item->expiresAfter(max(60, $lifetime - 120));

            return $token;
        });
    }

    private function tokenCacheKey(): string
    {
        return 'google_calendar.token.'.md5(trim($this->impersonate).'|'.$this->credentialsPath);
    }

    /**
     * Échange un JWT signé contre un jeton d'accès (grant « jwt-bearer »).
     *
     * @return array{0: string, 1: int} jeton, durée de vie en secondes
     *
     * @throws GoogleCalendarException
     */
    private function requestToken(): array
    {
        $credentials = $this->credentials();
        $now = time();
        $assertion = $this->signJwt([
            'iss' => $credentials['client_email'],
            'sub' => trim($this->impersonate),
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key']);

        try {
            $response = $this->http->request('POST', self::TOKEN_URL, [
                'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            $this->logger->warning('Google OAuth injoignable : '.$e->getMessage());
            throw new GoogleCalendarException('Google est injoignable pour le moment, réessayez plus tard.', 0, $e);
        }
        $data = is_array($data) ? $data : [];

        if ($status !== 200 || empty($data['access_token'])) {
            $error = trim((string) ($data['error'] ?? '').' '.(string) ($data['error_description'] ?? ''));
            $this->logger->warning(sprintf('Google OAuth : %d %s', $status, $error));
            throw new GoogleCalendarException(sprintf(
                'Google refuse l\'accès du compte de service (%s). Vérifiez la délégation à l\'échelle du domaine (ID client du compte de service + portée %s dans la console d\'administration Google Workspace) et l\'adresse à usurper.',
                $error !== '' ? $error : 'HTTP '.$status,
                self::SCOPE,
            ), $status);
        }

        return [(string) $data['access_token'], (int) ($data['expires_in'] ?? 3600)];
    }

    /**
     * @return array{client_email: string, private_key: string}
     *
     * @throws GoogleCalendarException
     */
    private function credentials(): array
    {
        $file = $this->credentialsFile();
        $data = $file !== null ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            throw new GoogleCalendarException('Le fichier d\'identifiants Google est invalide (clé JSON d\'un compte de service attendue : client_email et private_key).');
        }

        return ['client_email' => (string) $data['client_email'], 'private_key' => (string) $data['private_key']];
    }

    private function credentialsFile(): ?string
    {
        $path = trim($this->credentialsPath);
        if ($path === '') {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            $path = rtrim($this->projectDir, '/').'/'.$path;
        }

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * JWT RS256 : base64url(en-tête).base64url(charge).base64url(signature RSA-SHA256).
     *
     * @param array<string, mixed> $claims
     *
     * @throws GoogleCalendarException
     */
    private function signJwt(array $claims, string $privateKeyPem): string
    {
        $input = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR))
            .'.'.$this->base64Url(json_encode($claims, \JSON_THROW_ON_ERROR));

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new GoogleCalendarException('La clé privée du compte de service Google est illisible.');
        }
        $signature = '';
        if (!openssl_sign($input, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw new GoogleCalendarException('La signature du jeton Google a échoué (extension openssl).');
        }

        return $input.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
