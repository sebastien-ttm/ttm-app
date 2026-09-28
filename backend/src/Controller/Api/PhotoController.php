<?php

namespace App\Controller\Api;

use App\Entity\PhotoUpload;
use App\Entity\User;
use App\Repository\PhotoUploadRepository;
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
 * depuis l'appli. Les images sont servies par le backend
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
            return new JsonResponse(['enabled' => true, 'data' => $this->piwigo->listAlbums()]);
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
        ], Response::HTTP_CREATED);
    }

    /** Photos d'un album, les plus récentes d'abord. ?page=0,1… */
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
            foreach ($this->piwigo->listAlbums() as $a) {
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
            return $img + [
                'authorName' => $upload?->getUser()->getFullName(),
                'mine' => $mine,
                // Seules les photos postées depuis l'appli se suppriment ici ;
                // les autres se gèrent dans l'admin Piwigo.
                'canDelete' => $upload !== null && ($mine || $isAdmin),
            ];
        }, $result['images']);

        return new JsonResponse([
            'album' => $album,
            'images' => $images,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $result['total'],
        ]);
    }

    /**
     * Envoi d'UNE photo dans un album (multipart : photo, consent=1).
     * L'appli envoie les photos une par une (barre de progression, pas
     * de dépassement des limites PHP).
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
        if ($this->uploads->countByUserSince($user, new \DateTimeImmutable('-1 hour')) >= self::MAX_PHOTOS_PER_HOUR) {
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
                    sprintf('ttm-%d-%s.jpg', $user->getId(), bin2hex(random_bytes(4))),
                    $user->getFullName(),
                    'Photo envoyée depuis l\'appli TTM.',
                );
            } finally {
                @unlink($tmp);
            }
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }

        $upload = new PhotoUpload($user, $added['id'], $id, $albumName, $added['url']);
        $this->em->persist($upload);
        $this->em->flush();

        return new JsonResponse([
            'id' => $added['id'],
            'authorName' => $user->getFullName(),
            'mine' => true,
            'canDelete' => true,
        ], Response::HTTP_CREATED);
    }

    /** Suppression d'une photo postée depuis l'appli (auteur ou admin). */
    #[Route('/api/photos/images/{imageId}', methods: ['DELETE'], requirements: ['imageId' => '\d+'])]
    public function delete(int $imageId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $upload = $this->uploads->findOneByPiwigoImageId($imageId);
        if ($upload === null) {
            throw $this->createNotFoundException('Photo introuvable.');
        }
        if ($upload->getUser()->getId() !== $user->getId() && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['error' => 'Vous ne pouvez supprimer que vos propres photos.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->piwigo->deleteImage($imageId);
        } catch (PiwigoException $e) {
            return $this->unavailable($e);
        }
        $this->em->remove($upload);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
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

    private function unavailable(PiwigoException $e): JsonResponse
    {
        $this->logger->error('Piwigo indisponible', ['error' => $e->getMessage()]);
        return new JsonResponse(
            ['error' => 'La galerie photos est momentanément indisponible. Réessayez plus tard.'],
            Response::HTTP_BAD_GATEWAY,
        );
    }
}
