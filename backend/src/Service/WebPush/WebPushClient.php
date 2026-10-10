<?php

namespace App\Service\WebPush;

use App\Entity\WebPushSubscription;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Envoi de notifications Web Push, sans dépendance externe :
 *  - chiffrement du message en `aes128gcm` (RFC 8291) avec les clés du navigateur ;
 *  - authentification de l'application par VAPID (RFC 8292, JWT ES256).
 *
 * L'algorithme de chiffrement a été vérifié contre le vecteur de test de la
 * RFC 8291 (annexe A). Sans clés VAPID configurées (VAPID_PUBLIC_KEY /
 * VAPID_PRIVATE_KEY, voir la commande `app:push:vapid-keys`), le service est
 * inactif : isConfigured() renvoie false et rien n'est envoyé.
 */
class WebPushClient
{
    public const RESULT_OK = 'ok';
    /** Abonnement expiré ou révoqué (404 / 410) : à supprimer. */
    public const RESULT_GONE = 'gone';
    public const RESULT_ERROR = 'error';

    /** Taille de lot : requêtes lancées en parallèle (HttpClient multiplexe). */
    private const BATCH_SIZE = 50;
    /** Durée de vie d'un message non délivré (appareil éteint) : 1 jour. */
    private const DEFAULT_TTL = 86400;
    /** Un enregistrement chiffré tient en 4096 octets : 1 octet de délimiteur + 16 de tag + marge. */
    private const MAX_PAYLOAD_BYTES = 3500;

    /** @var array<string, string> JWT VAPID par origine (valable 12 h) */
    private array $jwtCache = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $pushLogger,
        private readonly string $vapidPublicKey = '',
        private readonly string $vapidPrivateKey = '',
        private readonly string $vapidSubject = 'mailto:noreply@example.com',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->vapidPublicKey !== '' && $this->vapidPrivateKey !== '';
    }

    /** Clé publique VAPID (base64url), donnée au navigateur pour s'abonner. */
    public function getPublicKey(): string
    {
        return $this->vapidPublicKey;
    }

    /**
     * Envoie le même message à plusieurs abonnements (en parallèle, par lots).
     *
     * @param list<WebPushSubscription> $subscriptions
     * @return array<int, string> id d'abonnement => RESULT_OK | RESULT_GONE | RESULT_ERROR
     */
    public function sendMany(array $subscriptions, string $payloadJson, int $ttl = self::DEFAULT_TTL, string $urgency = 'normal'): array
    {
        $results = [];
        if (!$this->isConfigured() || $subscriptions === []) {
            return $results;
        }
        if (strlen($payloadJson) > self::MAX_PAYLOAD_BYTES) {
            $this->pushLogger->warning('Web push : message trop long, non envoyé', ['bytes' => strlen($payloadJson)]);
            return $results;
        }

        foreach (array_chunk($subscriptions, self::BATCH_SIZE) as $chunk) {
            /** @var array<int, ResponseInterface> $pending */
            $pending = [];
            foreach ($chunk as $subscription) {
                $id = (int) $subscription->getId();
                try {
                    $pending[$id] = $this->http->request('POST', $subscription->getEndpoint(), [
                        'headers' => [
                            'Content-Type' => 'application/octet-stream',
                            'Content-Encoding' => 'aes128gcm',
                            'TTL' => (string) $ttl,
                            'Urgency' => $urgency,
                            'Authorization' => $this->vapidAuthorization($subscription->getEndpoint()),
                        ],
                        'body' => self::encrypt(
                            $payloadJson,
                            self::b64uDecode($subscription->getP256dh()),
                            self::b64uDecode($subscription->getAuthSecret()),
                        ),
                        'timeout' => 15,
                    ]);
                } catch (\Throwable $e) {
                    $results[$id] = self::RESULT_ERROR;
                    $this->pushLogger->error('Web push : préparation impossible', ['subscription' => $id, 'error' => $e->getMessage()]);
                }
            }

            foreach ($pending as $id => $response) {
                try {
                    $status = $response->getStatusCode();
                } catch (\Throwable $e) {
                    $results[$id] = self::RESULT_ERROR;
                    $this->pushLogger->warning('Web push : échec réseau', ['subscription' => $id, 'error' => $e->getMessage()]);
                    continue;
                }
                if ($status >= 200 && $status < 300) {
                    $results[$id] = self::RESULT_OK;
                } elseif ($status === 404 || $status === 410) {
                    $results[$id] = self::RESULT_GONE;
                } else {
                    $results[$id] = self::RESULT_ERROR;
                    $this->pushLogger->warning('Web push : refus du service push', ['subscription' => $id, 'status' => $status]);
                }
            }
        }

        return $results;
    }

    /** En-tête Authorization VAPID (RFC 8292) pour l'origine de l'endpoint. */
    private function vapidAuthorization(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Endpoint push invalide.');
        }
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $jwt = $this->jwtCache[$audience] ??= $this->vapidJwt($audience);

        return 'vapid t='.$jwt.', k='.$this->vapidPublicKey;
    }

    private function vapidJwt(string $audience): string
    {
        $header = self::b64uEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64uEncode((string) json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $this->vapidSubject,
        ]));
        $data = $header.'.'.$claims;

        $pem = self::privateKeyPem(self::b64uDecode($this->vapidPrivateKey), self::b64uDecode($this->vapidPublicKey));
        $der = '';
        if (!openssl_sign($data, $der, $pem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signature VAPID impossible (clés VAPID invalides ?).');
        }

        return $data.'.'.self::b64uEncode(self::derToRaw($der));
    }

    /**
     * Chiffre un message pour un abonnement (RFC 8291, aes128gcm) : corps de la
     * requête = salt(16) | taille d'enregistrement(4) | longueur de clé(1) | clé
     * publique du serveur(65) | message chiffré + tag(16).
     *
     * @param string               $uaPublic   clé publique du navigateur, 65 octets bruts (p256dh décodé)
     * @param string               $authSecret secret d'authentification, 16 octets bruts
     * @param string|null          $salt       16 octets aléatoires (fixé uniquement pour les tests)
     * @param \OpenSSLAsymmetricKey|null $ephemeral clé éphémère du serveur (fixée uniquement pour les tests)
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $salt = null, ?\OpenSSLAsymmetricKey $ephemeral = null): string
    {
        $salt ??= random_bytes(16);
        $ephemeral ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: null;
        if ($ephemeral === null) {
            throw new \RuntimeException('Génération de la clé éphémère impossible.');
        }

        $ec = openssl_pkey_get_details($ephemeral)['ec'] ?? null;
        if (!is_array($ec)) {
            throw new \RuntimeException('Clé éphémère illisible.');
        }
        // x et y peuvent perdre leurs zéros de tête : on les remet sur 32 octets.
        $asPublic = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        $ecdhSecret = openssl_pkey_derive(self::publicKeyPem($uaPublic), $ephemeral, 32);
        if ($ecdhSecret === false) {
            throw new \RuntimeException('Échange de clés ECDH impossible (clé du navigateur invalide ?).');
        }

        $keyInfo = "WebPush: info\0".$uaPublic.$asPublic;
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        // "\x02" : délimiteur du dernier (et seul) enregistrement.
        $ciphertext = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Chiffrement AES-GCM impossible.');
        }

        return $salt.pack('N', 4096).chr(strlen($asPublic)).$asPublic.$ciphertext.$tag;
    }

    /** Clé publique EC P-256 (65 octets bruts) → PEM (SubjectPublicKeyInfo). */
    private static function publicKeyPem(string $raw65): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw65;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** Clé privée EC P-256 (32 octets bruts + clé publique 65 octets) → PEM (RFC 5915). */
    public static function privateKeyPem(string $d32, string $public65): string
    {
        $der = hex2bin('30770201010420').$d32.hex2bin('a00a06082a8648ce3d030107a144034200').$public65;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** Signature ECDSA DER (openssl_sign) → R|S sur 64 octets, comme l'exige un JWT ES256. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $pos = 2 + (ord($der[1]) & 0x7f);
        }
        $rLength = ord($der[$pos + 1]);
        $r = substr($der, $pos + 2, $rLength);
        $pos += 2 + $rLength;
        $sLength = ord($der[$pos + 1]);
        $s = substr($der, $pos + 2, $sLength);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    public static function b64uEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }
}
