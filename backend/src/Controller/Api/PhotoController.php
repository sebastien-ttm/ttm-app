<?php

namespace App\Controller\Api;

use App\Entity\PhotoAlbumCover;
use App\Entity\PhotoUpload;
use App\Entity\User;
use App\Repository\PhotoAlbumCoverRepository;
use App\Repository\PhotoUploadRepository;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\ImageResizer;
use App\Service\Piwigo\PiwigoClient;
use App\Service\Piwigo\PiwigoException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Photos du club (onglet Social) : albums et photos de la galerie
 * Piwigo, réservés aux adhérents connectés. Tout adhérent peut créer
 * un album et y publier des photos (publication immédiate) ; il peut
 * supprimer les siennes, un admin peut supprimer toutes celles postées
 * depuis l'appli. Le staff (entraîneurs, encadrants, CoDir, admins) peut publier au nom d'un autre adhérent
 * (PhotoUpload::$uploadedBy garde qui a réellement envoyé la photo). La photo qui représente un album (et son
 * cadrage) se choisit depuis l'appli (PhotoAlbumCover). Les images sont servies par le backend
 * (albums Piwigo privés), voir PiwigoClient::fetchDerivative.
 */
#[IsGranted('ROLE_USER')]
class PhotoController extends AbstractController
{
    private const PER_PAGE = 60;
    private const MAX_UPLOAD_BYTES = 20_000_000;
    /** Plus grand côté conservé dans la galerie (qualité « souvenir »). */
    private const MAX_SIDE = 2048;
    /** Anti-abus : photos par adhérent et par heure. */
    private const MAX_PHOTOS_PER_HOUR = 200;

    public function __construct(
        private readonly PiwigoClient $piwigo,
        private readonly PhotoUploadRepository $uploads,
        private readonly PhotoAlbumCoverRepository $covers,
        private readonly UserRepository $users,
        private readonly AvatarService $avatars,
        private readonly ImageResizer $resizer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Albums (enfants de l'album racine), le plus récent d'abord. */
    #[Route('/api/photos/albums', methods: ['GET'])]
    public function albums(): JsonResponse
    {
        if (!$this->piwigo->isConfigured()) {
            return new JsonResponse(['enabled' => false, 'data' => []]);
        }
        try {
            return new JsonResponse(['enabled' => true, 'data' => $this->withCovers($this->piwigo->listAlbums())]);
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }
    }

    /** Création d'un album. JSON : name, comment?. */
    #[Route('/api/photos/albums', methods: ['POST'])]
    public function createAlbum(Request $request): JsonResponse
    {
        if (!$this->piwigo->isConfigured()) {
            return new JsonResponse(['error' => 'La galerie photos n\'est pas encore configurée.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $payload = json_decode($request->getContent(), true);
        $name = is_array($payload) ? trim((string) ($payload['name'] ?? '')) : '';
        $comment = is_array($payload) ? trim((string) ($payload['comment'] ?? '')) : '';
        if ($name === '') {
            return new JsonResponse(['error' => 'Le nom de l\'album ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($name) > 100) {
            return new JsonResponse(['error' => 'Nom trop long (100 caractères max).'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($comment) > 500) {
            return new JsonResponse(['error' => 'Description trop longue (500 caractères max).'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        // Texte brut uniquement : Piwigo accepte du HTML dans nom/description.
        $name = strip_tags($name);
        $comment = strip_tags($comment);
        $comment = trim(($comment !== '' ? $comment."\n\n" : '').'Album créé depuis l\'appli par '.$user->getFullName().'.');

        try {
            $albumId = $this->piwigo->createAlbum($name, $comment);
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }

        return new JsonResponse([
            'id' => $albumId,
            'name' => $name,
            'comment' => $comment,
            'nbImages' => 0,
            'dateLast' => null,
            'coverImageId' => null,
            'coverFocusX' => PhotoAlbumCover::CENTER,
            'coverFocusY' => PhotoAlbumCover::CENTER,
        ], Response::HTTP_CREATED);
    }

    /** Photos d'un album dans l'ordre chronologique d'ajout (les plus anciennes d'abord). ?page=0,1… */
    #[Route('/api/photos/albums/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function album(int $id, Request $request): JsonResponse
    {
        if (!$this->piwigo->isConfigured()) {
            throw $this->createNotFoundException();
        }
        /** @var User $user */
        $user = $this->getUser();
        $page = max(0, (int) $request->query->get('page', 0));

        try {
            $album = null;
            foreach ($this->withCovers($this->piwigo->listAlbums()) as $a) {
                if ($a['id'] === $id) {
                    $album = $a;
                    break;
                }
            }
            if ($album === null) {
                throw $this->createNotFoundException('Album introuvable.');
            }
            $result = $this->piwigo->getAlbumImages($id, $page, self::PER_PAGE);
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }

        $known = $this->uploads->findIndexedByPiwigoImageIds(array_map(fn ($i) => $i['id'], $result['images']));
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $images = array_map(function (array $img) use ($known, $user, $isAdmin) {
            $upload = $known[$img['id']] ?? null;
            $mine = $upload !== null && $upload->getUser()->getId() === $user->getId();
            $sentByMe = $upload?->getUploadedBy()?->getId() === $user->getId();
            return $img + [
                'authorName' => $upload?->getUser()->getFullName(),
                'mine' => $mine,
                // Seules les photos postées depuis l'appli se suppriment ici ;
                // les autres se gèrent dans l'admin Piwigo.
                'canDelete' => $upload !== null && ($mine || $sentByMe || $isAdmin),
            ];
        }, $result['images']);

        return new JsonResponse([
            'album' => $album + [
                'canEditCover' => $this->canEditCover($user, $id),
                'canPostForOthers' => $this->canPostForOthers($user),
            ],
            'images' => $images,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $result['total'],
        ]);
    }

    /**
     * Envoi d'UNE photo dans un album (multipart : photo, consent=1, authorId?).
     * L'appli envoie les photos une par une (barre de progression, pas
     * de dépassement des limites PHP). `authorId` : adhérent au nom de qui la photo est publiée, réservé au staff
     * (voir canPostForOthers) ; absent = l'envoyeur lui-même.
     */
    #[Route('/api/photos/albums/{id}/images', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function upload(int $id, Request $request): JsonResponse
    {
        if (!$this->piwigo->isConfigured()) {
            return new JsonResponse(['error' => 'La galerie photos n\'est pas encore configurée.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        /** @var User $user */
        $user = $this->getUser();

        if ($request->request->get('consent') !== '1') {
            return new JsonResponse(
                ['error' => 'Merci de confirmer que les personnes photographiées sont d\'accord.'],
                Response::HTTP_BAD_REQUEST,
            );
        }
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => 'Photo manquante ou envoi interrompu.'], Response::HTTP_BAD_REQUEST);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return new JsonResponse(['error' => 'Photo trop lourde (20 Mo max).'], Response::HTTP_BAD_REQUEST);
        }

        // Publication au nom d'un autre adhérent : staff seulement, vers un compte actif.
        $author = $user;
        $rawAuthor = (string) $request->request->get('authorId', '');
        if ($rawAuthor !== '' && $rawAuthor !== (string) $user->getId()) {
            if (!$this->canPostForOthers($user)) {
                return new JsonResponse(
                    ['error' => 'Seul le staff peut publier des photos au nom d\'un autre adhérent.'],
                    Response::HTTP_FORBIDDEN,
                );
            }
            $found = ctype_digit($rawAuthor) ? $this->em->find(User::class, (int) $rawAuthor) : null;
            if (!$found instanceof User || !$found->isActive()) {
                return new JsonResponse(['error' => 'Adhérent introuvable.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $author = $found;
        }
        $onBehalf = $author->getId() !== $user->getId();

        if ($this->uploads->countByUserSince($author, new \DateTimeImmutable('-1 hour')) >= self::MAX_PHOTOS_PER_HOUR) {
            return new JsonResponse(
                ['error' => 'Beaucoup de photos envoyées en peu de temps. Réessayez dans un moment.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        try {
            if (!$this->piwigo->isAllowedAlbum($id) || $id === $this->piwigo->getRootAlbumId()) {
                throw $this->createNotFoundException('Album introuvable.');
            }
            $albumName = '#'.$id;
            foreach ($this->piwigo->listAlbums() as $a) {
                if ($a['id'] === $id) {
                    $albumName = $a['name'];
                    break;
                }
            }

            // Normalisation : JPEG, orientation appliquée, 2048 px max.
            $tmp = tempnam(sys_get_temp_dir(), 'ttm-photo-');
            if (!$this->resizer->compressToJpeg($file->getPathname(), $tmp, $file->getMimeType(), self::MAX_SIDE, 85)) {
                @unlink($tmp);
                return new JsonResponse(
                    ['error' => 'Format de photo non pris en charge (JPEG, PNG ou WebP).'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            try {
                $added = $this->piwigo->addImage(
                    $id,
                    $tmp,
                    sprintf('ttm-%d-%s.jpg', $author->getId(), bin2hex(random_bytes(4))),
                    $author->getFullName(),
                    $onBehalf
                        ? 'Photo envoyée depuis l\'appli TTM par '.$user->getFullName().'.'
                        : 'Photo envoyée depuis l\'appli TTM.',
                );
            } finally {
                @unlink($tmp);
            }
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }

        $upload = new PhotoUpload($author, $added['id'], $id, $albumName, $added['url'], $onBehalf ? $user : null);
        $this->em->persist($upload);
        $this->em->flush();

        return new JsonResponse([
            'id' => $added['id'],
            'authorName' => $author->getFullName(),
            'mine' => !$onBehalf,
            'canDelete' => true,
        ], Response::HTTP_CREATED);
    }

    /**
     * Adhérents au nom de qui le staff peut publier des photos : identité et photo seulement (pas de coordonnées,
     * contrairement à l'annuaire du staff sportif).
     */
    #[Route('/api/photos/authors', methods: ['GET'])]
    public function authors(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->canPostForOthers($user)) {
            throw $this->createAccessDeniedException();
        }

        $members = array_map(fn (User $u): array => [
            'id' => $u->getId(),
            'nom' => $u->getNom(),
            'prenom' => $u->getPrenom(),
            'avatarUrl' => $this->avatars->urlFor($u),
        ], $this->users->findAdherentsForStaffDirectory());

        return new JsonResponse(['data' => $members]);
    }

    /** Suppression d'une photo postée depuis l'appli (auteur, envoyeur pour le compte de l'auteur, ou admin). */
    #[Route('/api/photos/images/{imageId}', methods: ['DELETE'], requirements: ['imageId' => '\d+'])]
    public function delete(int $imageId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $upload = $this->uploads->findOneByPiwigoImageId($imageId);
        if ($upload === null) {
            throw $this->createNotFoundException('Photo introuvable.');
        }
        $isAuthor = $upload->getUser()->getId() === $user->getId();
        $sentByMe = $upload->getUploadedBy()?->getId() === $user->getId();
        if (!$isAuthor && !$sentByMe && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['error' => 'Vous ne pouvez supprimer que vos propres photos.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->piwigo->deleteImage($imageId);
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }
        $this->em->remove($upload);
        $this->em->flush();
        // Si c'était la photo de couverture de son album, l'album reprend celle de Piwigo.
        $this->covers->forgetImage($imageId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Photo qui représente l'album dans la liste, et son cadrage. JSON : imageId (une photo de l'album ; null = laisser
     * Piwigo choisir), focusX / focusY (0-100 : position de la photo dans la vignette, 50 = centrée). Réservé aux
     * encadrants et aux adhérents qui ont posté des photos dans cet album.
     */
    #[Route('/api/photos/albums/{id}/cover', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function setCover(int $id, Request $request): JsonResponse
    {
        if (!$this->piwigo->isConfigured()) {
            return new JsonResponse(['error' => 'La galerie photos n\'est pas encore configurée.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Requête invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $imageId = $payload['imageId'] ?? null;
        if ($imageId !== null && (!is_numeric($imageId) || (int) $imageId <= 0)) {
            return new JsonResponse(['error' => 'Photo invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $imageId = $imageId !== null ? (int) $imageId : null;

        try {
            if (!$this->piwigo->isAllowedAlbum($id) || $id === $this->piwigo->getRootAlbumId()) {
                throw $this->createNotFoundException('Album introuvable.');
            }
            if (!$this->canEditCover($user, $id)) {
                return new JsonResponse(
                    ['error' => 'Seuls les encadrants et les adhérents qui ont ajouté des photos à cet album peuvent en choisir la couverture.'],
                    Response::HTTP_FORBIDDEN,
                );
            }
            if ($imageId !== null) {
                $where = $this->piwigo->imageLocation($imageId);
                if ($where === null || !in_array($id, array_column($where['albums'], 'id'), true)) {
                    return new JsonResponse(['error' => 'Cette photo n\'est pas dans l\'album.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }

        // Sans photo choisie, le cadrage n'a pas de sens : retour au centre.
        $focusX = $imageId !== null ? self::focus($payload['focusX'] ?? null) : PhotoAlbumCover::CENTER;
        $focusY = $imageId !== null ? self::focus($payload['focusY'] ?? null) : PhotoAlbumCover::CENTER;
        $cover = $this->covers->findOneByAlbum($id) ?? new PhotoAlbumCover($id);
        $cover->set($imageId, $focusX, $focusY);
        $this->em->persist($cover);
        $this->em->flush();

        return new JsonResponse(['coverImageId' => $imageId, 'coverFocusX' => $focusX, 'coverFocusY' => $focusY]);
    }

    /**
     * Image d'une photo : 'grid' (vignette de la grille) ou 'full'
     * (plein écran). Authentifiée (header ou ?bearer=), servie depuis le
     * cache disque du backend.
     */
    #[Route('/api/photos/images/{imageId}/{variant}', methods: ['GET'], requirements: ['imageId' => '\d+', 'variant' => 'grid|full'])]
    public function image(int $imageId, string $variant): Response
    {
        if (!$this->piwigo->isConfigured()) {
            throw $this->createNotFoundException();
        }
        try {
            $path = $this->piwigo->fetchDerivative($imageId, $variant);
        } catch (PiwigoException $e) {
            $this->logger->warning('Piwigo : image indisponible', ['imageId' => $imageId, 'error' => $e->getMessage()]);
            $path = null;
        }
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/jpeg');
        // Privé : contenu réservé aux adhérents, jamais en cache partagé.
        $response->setPrivate();
        $response->setMaxAge(7 * 86400);
        return $response;
    }

    /**
     * Applique aux albums de Piwigo la couverture choisie par les adhérents : photo (à défaut celle que Piwigo propose)
     * et cadrage.
     *
     * @param list<array<string, mixed>> $albums
     * @return list<array<string, mixed>>
     */
    private function withCovers(array $albums): array
    {
        try {
            $covers = $this->covers->indexedByAlbum();
        } catch (\Throwable) {
            $covers = []; // table pas encore migrée : on garde les couvertures de Piwigo
        }

        return array_map(static function (array $a) use ($covers) {
            $cover = $covers[$a['id']] ?? null;
            $chosen = $cover?->getCoverImageId();

            return [
                'coverImageId' => $chosen ?? $a['coverImageId'],
                'coverFocusX' => $chosen !== null ? $cover->getFocusX() : PhotoAlbumCover::CENTER,
                'coverFocusY' => $chosen !== null ? $cover->getFocusY() : PhotoAlbumCover::CENTER,
            ] + $a;
        }, $albums);
    }

    /** Encadrants, ou adhérent ayant posté au moins une photo dans l'album. */
    private function canEditCover(User $user, int $albumId): bool
    {
        return $this->isGranted('ROLE_ENTRAINEUR') || $this->uploads->countByUserInAlbum($user, $albumId) > 0;
    }

    /**
     * Publier au nom d'un autre adhérent : mêmes profils que l'espace « Staff » de l'appli (émargement) — entraîneurs,
     * encadrants, membres du CoDir et administrateurs.
     */
    private function canPostForOthers(User $user): bool
    {
        return $user->isEntraineur() || $user->isEncadrant() || $user->getBoardRole() !== null || $user->isAdmin();
    }

    /** Position 0-100 envoyée par l'appli ; 50 (centrée) si absente ou invalide. */
    private static function focus(mixed $value): int
    {
        return is_numeric($value) ? (int) round(max(0.0, min(100.0, (float) $value))) : PhotoAlbumCover::CENTER;
    }

    private function unavailable(PiwigoException $e): JsonResponse
    {
        $this->logger->error('Piwigo indisponible', ['error' => $e->getMessage()]);
        return new JsonResponse(
            ['error' => 'La galerie photos est momentanément indisponible. Réessayez plus tard.'],
            Response::HTTP_BAD_GATEWAY,
        );
    }
}
