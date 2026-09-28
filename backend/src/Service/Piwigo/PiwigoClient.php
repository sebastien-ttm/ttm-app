<?php

namespace App\Service\Piwigo;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API web de la galerie Piwigo (ws.php) utilisée par
 * « Photos du club » (onglet Social).
 *
 * L'appli ne parle jamais directement à Piwigo : le backend se connecte
 * avec un compte ADMIN dédié (PIWIGO_USERNAME / PIWIGO_PASSWORD) — les
 * méthodes d'écriture (pwg.images.addSimple, pwg.categories.add,
 * pwg.images.delete) sont réservées aux admins Piwigo.
 *
 * Périmètre : l'album racine PIWIGO_ROOT_ALBUM_ID (privé) et ses albums
 * enfants directs. Les albums sont privés → les images ne sont pas
 * accessibles publiquement ; le backend les récupère avec sa session et
 * les sert à l'appli (fetchDerivative), avec un cache disque.
 */
class PiwigoClient
{
    private const SESSION_CACHE_KEY = 'piwigo.session';
    private const ALLOWED_ALBUMS_CACHE_KEY = 'piwigo.allowed_albums';

    /** Tailles Piwigo par usage, par ordre de préférence. */
    private const VARIANTS = [
        'grid' => ['small', 'medium', 'xsmall', 'thumb'],
        'full' => ['xlarge', 'large', 'xxlarge', 'medium'],
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $rootAlbumId,
        private readonly string $membersGroupId,
        private readonly string $cacheDir,
    ) {
    }

    /** Galerie utilisable : URL, identifiants et album racine renseignés. */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->username !== '' && $this->password !== ''
            && ctype_digit($this->rootAlbumId);
    }

    public function getRootAlbumId(): int
    {
        return (int) $this->rootAlbumId;
    }

    /**
     * Albums enfants directs de l'album racine, le plus récent d'abord.
     *
     * @return list<array{id: int, name: string, comment: ?string, nbImages: int, dateLast: ?string, coverImageId: ?int}>
     */
    public function listAlbums(): array
    {
        $root = $this->getRootAlbumId();
        $result = $this->call('pwg.categories.getList', ['cat_id' => $root, 'recursive' => 'false']);

        $albums = [];
        foreach ($result['categories'] ?? [] as $c) {
            if ((int) $c['id'] === $root || (int) ($c['id_uppercat'] ?? 0) !== $root) {
                continue;
            }
            $albums[] = [
                'id' => (int) $c['id'],
                'name' => self::decode((string) $c['name']),
                'comment' => isset($c['comment']) && $c['comment'] !== '' ? self::decode(strip_tags((string) $c['comment'])) : null,
                'nbImages' => (int) ($c['total_nb_images'] ?? $c['nb_images'] ?? 0),
                'dateLast' => $c['max_date_last'] ?? $c['date_last'] ?? null,
                'coverImageId' => !empty($c['representative_picture_id']) ? (int) $c['representative_picture_id'] : null,
            ];
        }
        usort($albums, fn ($a, $b) => $b['id'] <=> $a['id']);

        // Profite de l'appel pour rafraîchir la liste des albums autorisés.
        $this->cache->delete(self::ALLOWED_ALBUMS_CACHE_KEY);

        return $albums;
    }

    /** Album racine + ses enfants directs : seuls albums accessibles depuis l'appli. */
    public function isAllowedAlbum(int $albumId): bool
    {
        return in_array($albumId, $this->allowedAlbumIds(), true);
    }

    /**
     * Crée un album (privé) sous l'album racine et, si configuré, ouvre
     * son accès au groupe Piwigo des adhérents.
     *
     * @return int id du nouvel album
     */
    public function createAlbum(string $name, ?string $comment): int
    {
        $params = [
            'name' => $name,
            'parent' => $this->getRootAlbumId(),
            'status' => 'private',
            'position' => 'first',
        ];
        if ($comment !== null && $comment !== '') {
            $params['comment'] = $comment;
        }
        $result = $this->call('pwg.categories.add', $params, true);
        $albumId = (int) ($result['id'] ?? 0);
        if ($albumId === 0) {
            throw new PiwigoException('pwg.categories.add : id manquant dans la réponse.');
        }

        if (ctype_digit($this->membersGroupId)) {
            try {
                $this->call('pwg.permissions.add', [
                    'cat_id' => $albumId,
                    'group_id' => (int) $this->membersGroupId,
                    'pwg_token' => $this->pwgToken(),
                ], true);
            } catch (PiwigoException $e) {
                // L'album existe : on ne bloque pas l'adhérent pour une
                // permission web Piwigo (l'appli y accède de toute façon).
                $this->logger->warning('Piwigo : permission groupe non ajoutée', ['albumId' => $albumId, 'error' => $e->getMessage()]);
            }
        }

        $this->cache->delete(self::ALLOWED_ALBUMS_CACHE_KEY);
        return $albumId;
    }

    /**
     * Photos d'un album, les plus récentes d'abord (page 0 = première).
     *
     * @return array{images: list<array{id: int, width: int, height: int, dateAvailable: ?string}>, total: int}
     */
    public function getAlbumImages(int $albumId, int $page, int $perPage): array
    {
        $result = $this->call('pwg.categories.getImages', [
            'cat_id' => $albumId,
            'page' => $page,
            'per_page' => $perPage,
            'order' => 'date_available desc',
        ]);

        $images = [];
        foreach ($result['images'] ?? [] as $img) {
            $this->rememberImage($img);
            $images[] = [
                'id' => (int) $img['id'],
                'width' => (int) ($img['width'] ?? 0),
                'height' => (int) ($img['height'] ?? 0),
                'dateAvailable' => $img['date_available'] ?? null,
            ];
        }

        return [
            'images' => $images,
            'total' => (int) ($result['paging']['total_count'] ?? count($images)),
        ];
    }

    /**
     * Envoie une photo (JPEG déjà réduit) dans un album.
     *
     * @return array{id: int, url: ?string}
     */
    public function addImage(int $albumId, string $filePath, string $fileName, string $author, ?string $comment): array
    {
        $result = $this->call('pwg.images.addSimple', [], true, function () use ($albumId, $filePath, $fileName, $author, $comment) {
            $fields = [
                'image' => DataPart::fromPath($filePath, $fileName, 'image/jpeg'),
                'category' => (string) $albumId,
                'author' => $author,
            ];
            if ($comment !== null) {
                $fields['comment'] = $comment;
            }
            return new FormDataPart($fields);
        });

        $imageId = (int) ($result['image_id'] ?? 0);
        if ($imageId === 0) {
            throw new PiwigoException('pwg.images.addSimple : image_id manquant dans la réponse.');
        }
        return ['id' => $imageId, 'url' => $result['url'] ?? null];
    }

    public function deleteImage(int $imageId): void
    {
        $this->call('pwg.images.delete', ['image_id' => $imageId, 'pwg_token' => $this->pwgToken()], true);
        $this->cache->delete('piwigo.img.'.$imageId);
        foreach (array_keys(self::VARIANTS) as $variant) {
            @unlink($this->cachePath($imageId, $variant));
        }
    }

    /**
     * Chemin local d'une version de la photo ('grid' ou 'full'),
     * téléchargée depuis Piwigo au premier accès puis servie depuis le
     * cache disque. null si la photo n'existe pas ou n'appartient pas à
     * un album autorisé.
     */
    public function fetchDerivative(int $imageId, string $variant): ?string
    {
        if (!isset(self::VARIANTS[$variant])) {
            return null;
        }
        $path = $this->cachePath($imageId, $variant);
        if (is_file($path)) {
            return $path;
        }

        $meta = $this->imageMeta($imageId);
        if ($meta === null || array_intersect($meta['albums'], $this->allowedAlbumIds()) === []) {
            return null;
        }

        $url = null;
        foreach (self::VARIANTS[$variant] as $type) {
            if (!empty($meta['derivatives'][$type])) {
                $url = $meta['derivatives'][$type];
                break;
            }
        }
        $url ??= $meta['element'];
        if ($url === null) {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim($this->baseUrl, '/').'/'.ltrim($url, '/');
        }

        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Cookie' => $this->sessionCookie()],
                'timeout' => 30,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $type = $response->getHeaders()['content-type'][0] ?? '';
            if (!str_starts_with($type, 'image/')) {
                return null;
            }
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0775, true);
            }
            $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            $fh = fopen($tmp, 'wb');
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($fh, $chunk->getContent());
            }
            fclose($fh);
            rename($tmp, $path);
            return $path;
        } catch (\Throwable $e) {
            $this->logger->warning('Piwigo : téléchargement image impossible', ['imageId' => $imageId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // ------------------------------------------------------------------

    /** @return list<int> */
    private function allowedAlbumIds(): array
    {
        return $this->cache->get(self::ALLOWED_ALBUMS_CACHE_KEY, function (ItemInterface $item) {
            $item->expiresAfter(300);
            $root = $this->getRootAlbumId();
            $result = $this->call('pwg.categories.getList', ['cat_id' => $root, 'recursive' => 'false']);
            $ids = [$root];
            foreach ($result['categories'] ?? [] as $c) {
                if ((int) ($c['id_uppercat'] ?? 0) === $root) {
                    $ids[] = (int) $c['id'];
                }
            }
            return $ids;
        });
    }

    /**
     * Métadonnées d'une image (albums, URLs des tailles) : depuis le
     * cache alimenté par getAlbumImages, sinon via pwg.images.getInfo.
     *
     * @return array{albums: list<int>, derivatives: array<string, string>, element: ?string}|null
     */
    private function imageMeta(int $imageId): ?array
    {
        return $this->cache->get('piwigo.img.'.$imageId, function (ItemInterface $item) use ($imageId) {
            $item->expiresAfter(86400);
            try {
                $info = $this->call('pwg.images.getInfo', ['image_id' => $imageId]);
            } catch (PiwigoException) {
                $item->expiresAfter(60);
                return null;
            }
            return self::extractMeta($info);
        });
    }

    /** @param array<string, mixed> $img */
    private function rememberImage(array $img): void
    {
        $key = 'piwigo.img.'.(int) $img['id'];
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($img) {
            $item->expiresAfter(86400);
            return self::extractMeta($img);
        });
    }

    /**
     * @param array<string, mixed> $img
     * @return array{albums: list<int>, derivatives: array<string, string>, element: ?string}
     */
    private static function extractMeta(array $img): array
    {
        $derivatives = [];
        foreach ($img['derivatives'] ?? [] as $type => $d) {
            if (is_array($d) && !empty($d['url'])) {
                $derivatives[$type] = (string) $d['url'];
            }
        }
        return [
            'albums' => array_values(array_map(fn ($c) => (int) $c['id'], $img['categories'] ?? [])),
            'derivatives' => $derivatives,
            'element' => $img['element_url'] ?? null,
        ];
    }

    private function cachePath(int $imageId, string $variant): string
    {
        return rtrim($this->cacheDir, '/\\').DIRECTORY_SEPARATOR.$imageId.'-'.$variant.'.jpg';
    }

    /** Jeton anti-CSRF Piwigo lié à la session (requis pour supprimer, gérer les droits). */
    private function pwgToken(): string
    {
        $status = $this->call('pwg.session.getStatus');
        $token = (string) ($status['pwg_token'] ?? '');
        if ($token === '') {
            throw new PiwigoException('pwg_token indisponible.');
        }
        return $token;
    }

    /**
     * Appel de l'API. En cas de session expirée (401/403), reconnexion
     * puis nouvel essai unique. $multipart : fabrique du corps multipart
     * (appelée à chaque essai, un corps en flux ne se rejoue pas).
     *
     * @param array<string, scalar> $params
     * @param (callable(): FormDataPart)|null $multipart
     * @return array<string, mixed> le champ « result » de la réponse
     */
    private function call(string $method, array $params = [], bool $post = false, ?callable $multipart = null, bool $retry = true): array
    {
        if (!$this->isConfigured()) {
            throw new PiwigoException('Galerie Piwigo non configurée.');
        }

        $options = [
            'query' => ['format' => 'json', 'method' => $method] + ($post ? [] : $params),
            'headers' => ['Cookie' => $this->sessionCookie()],
            'timeout' => 60,
        ];
        if ($multipart !== null) {
            $form = $multipart();
            $options['headers'] = array_merge($options['headers'], $form->getPreparedHeaders()->toArray());
            $options['body'] = $form->bodyToIterable();
        } elseif ($post) {
            $options['body'] = $params;
        }

        try {
            $response = $this->http->request($post ? 'POST' : 'GET', $this->wsUrl(), $options);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (\Throwable $e) {
            throw new PiwigoException($method.' : '.$e->getMessage(), 0, $e);
        }

        $failed = !is_array($data) || ($data['stat'] ?? '') !== 'ok';
        if ($failed && $retry && (in_array($status, [401, 403], true) || in_array((int) ($data['err'] ?? 0), [401, 403], true))) {
            $this->cache->delete(self::SESSION_CACHE_KEY);
            return $this->call($method, $params, $post, $multipart, false);
        }
        if ($failed) {
            throw new PiwigoException(sprintf('%s : %s', $method, is_array($data) ? ($data['message'] ?? 'échec') : 'réponse invalide (HTTP '.$status.')'));
        }

        return is_array($data['result'] ?? null) ? $data['result'] : ['value' => $data['result'] ?? null];
    }

    /** Cookie(s) de session du compte technique, mis en cache 30 min. */
    private function sessionCookie(): string
    {
        return $this->cache->get(self::SESSION_CACHE_KEY, function (ItemInterface $item) {
            $item->expiresAfter(1800);
            return $this->login();
        });
    }

    private function login(): string
    {
        try {
            $response = $this->http->request('POST', $this->wsUrl(), [
                'query' => ['format' => 'json', 'method' => 'pwg.session.login'],
                'body' => ['username' => $this->username, 'password' => $this->password],
                'timeout' => 30,
            ]);
            $data = json_decode($response->getContent(false), true);
            $setCookies = $response->getHeaders(false)['set-cookie'] ?? [];
        } catch (\Throwable $e) {
            throw new PiwigoException('Connexion Piwigo impossible : '.$e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data['stat'] ?? '') !== 'ok') {
            throw new PiwigoException('Connexion Piwigo refusée (identifiants PIWIGO_USERNAME / PIWIGO_PASSWORD ?).');
        }

        $pairs = [];
        foreach ($setCookies as $header) {
            $pair = trim(explode(';', $header, 2)[0]);
            // Piwigo renvoie parfois « pwg_id=deleted » avant le vrai cookie.
            if ($pair !== '' && !str_ends_with($pair, '=deleted')) {
                $pairs[explode('=', $pair, 2)[0]] = $pair;
            }
        }
        if ($pairs === []) {
            throw new PiwigoException('Connexion Piwigo : aucun cookie de session reçu.');
        }
        return implode('; ', $pairs);
    }

    private function wsUrl(): string
    {
        return rtrim($this->baseUrl, '/').'/ws.php';
    }

    private static function decode(string $s): string
    {
        return html_entity_decode($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
