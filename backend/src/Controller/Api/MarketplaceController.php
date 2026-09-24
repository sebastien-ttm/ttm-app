<?php

namespace App\Controller\Api;

use App\Entity\MarketplaceListing;
use App\Entity\MarketplaceListingPhoto;
use App\Entity\User;
use App\Repository\MarketplaceListingPhotoRepository;
use App\Repository\MarketplaceListingRepository;
use App\Service\Marketplace\MarketplaceListingPhotoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bourse aux équipements (onglet Club) : annonces d'adhérents pour du
 * matériel/des affaires d'occasion. Contact entre adhérents géré hors
 * app (WhatsApp, via le téléphone de l'auteur exposé dans le détail).
 */
#[IsGranted('ROLE_USER')]
class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceListingRepository $listings,
        private readonly MarketplaceListingPhotoRepository $photosRepo,
        private readonly MarketplaceListingPhotoService $photoService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Annonces publiées, plus récentes d'abord — visible de tout le club.
     */
    #[Route('/api/marketplace/listings', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (MarketplaceListing $l) => $this->serializeSummary($l), $this->listings->findPublished()),
        ]);
    }

    /**
     * Mes annonces (publiées ET en pause) — pour la gestion perso.
     */
    #[Route('/api/marketplace/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse([
            'data' => array_map(fn (MarketplaceListing $l) => $this->serializeDetail($l), $this->listings->findAllByAuthor($user)),
        ]);
    }

    /**
     * Détail d'une annonce. Une annonce en pause n'est visible que par
     * son auteur (pour prévisualiser/gérer) — 404 sinon, pas de 403
     * qui laisserait deviner l'existence.
     */
    #[Route('/api/marketplace/listings/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->listings->find($id);
        if ($listing === null) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }
        if ($listing->isPaused() && $listing->getAuthor()->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }
        return new JsonResponse($this->serializeDetail($listing));
    }

    /**
     * Création. Multipart : title, description, photos[] (0 à 5 fichiers).
     */
    #[Route('/api/marketplace/listings', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $title = trim((string) $request->request->get('title', ''));
        $description = trim((string) $request->request->get('description', ''));
        if ($title === '') {
            return new JsonResponse(['error' => 'Le titre ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($title) > 120) {
            return new JsonResponse(['error' => 'Titre trop long (120 caractères max).'], Response::HTTP_BAD_REQUEST);
        }
        if ($description === '') {
            return new JsonResponse(['error' => 'La description ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($description) > 3000) {
            return new JsonResponse(['error' => 'Description trop longue (3000 caractères max).'], Response::HTTP_BAD_REQUEST);
        }

        $files = $request->files->all('photos');
        if (count($files) > MarketplaceListingPhotoService::MAX_PHOTOS) {
            return new JsonResponse(
                ['error' => sprintf('Maximum %d photos.', MarketplaceListingPhotoService::MAX_PHOTOS)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $listing = new MarketplaceListing($user);
        $listing->setTitle($title);
        $listing->setDescription($description);
        $this->em->persist($listing);
        // Flush requis avant les photos : le dossier de stockage est
        // nommé d'après l'id de l'annonce (voir MarketplaceListingPhotoService).
        $this->em->flush();

        foreach ($files as $i => $file) {
            try {
                $this->photoService->add($listing, $file, $i);
            } catch (\RuntimeException $e) {
                // Annonce à moitié créée : on annule tout plutôt que de
                // laisser une annonce sans certaines de ses photos.
                $this->photoService->removeAllFiles($listing);
                $this->em->remove($listing);
                $this->em->flush();
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        $this->em->flush();

        return new JsonResponse($this->serializeDetail($listing), Response::HTTP_CREATED);
    }

    /**
     * Édition du titre/texte (JSON). Les photos se gèrent via les
     * endpoints dédiés ci-dessous.
     */
    #[Route('/api/marketplace/listings/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->findOwnedOr404($id, $user);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('title', $payload)) {
            $title = trim((string) $payload['title']);
            if ($title === '') {
                return new JsonResponse(['error' => 'Le titre ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            if (mb_strlen($title) > 120) {
                return new JsonResponse(['error' => 'Titre trop long (120 caractères max).'], Response::HTTP_BAD_REQUEST);
            }
            $listing->setTitle($title);
        }
        if (array_key_exists('description', $payload)) {
            $description = trim((string) $payload['description']);
            if ($description === '') {
                return new JsonResponse(['error' => 'La description ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            if (mb_strlen($description) > 3000) {
                return new JsonResponse(['error' => 'Description trop longue (3000 caractères max).'], Response::HTTP_BAD_REQUEST);
            }
            $listing->setDescription($description);
        }
        $listing->touchUpdatedAt();
        $this->em->flush();

        return new JsonResponse($this->serializeDetail($listing));
    }

    /**
     * Ajoute une ou plusieurs photos à une annonce existante (multipart,
     * champ photos[]). Refuse si le total dépasserait 5.
     */
    #[Route('/api/marketplace/listings/{id}/photos', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addPhotos(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->findOwnedOr404($id, $user);

        $files = $request->files->all('photos');
        if ($files === []) {
            return new JsonResponse(['error' => 'Aucune photo reçue.'], Response::HTTP_BAD_REQUEST);
        }
        $existing = $listing->getPhotos()->count();
        if ($existing + count($files) > MarketplaceListingPhotoService::MAX_PHOTOS) {
            return new JsonResponse(['error' => sprintf(
                'Maximum %d photos par annonce (%d déjà présente%s).',
                MarketplaceListingPhotoService::MAX_PHOTOS,
                $existing,
                $existing > 1 ? 's' : '',
            )], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $position = $existing;
        foreach ($files as $file) {
            try {
                $this->photoService->add($listing, $file, $position);
            } catch (\RuntimeException $e) {
                // Garde les photos déjà ajoutées avec succès avant l'échec.
                $this->em->flush();
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $position++;
        }
        $listing->touchUpdatedAt();
        $this->em->flush();

        return new JsonResponse($this->serializeDetail($listing), Response::HTTP_CREATED);
    }

    #[Route('/api/marketplace/listings/{id}/photos/{photoId}', methods: ['DELETE'], requirements: ['id' => '\d+', 'photoId' => '\d+'])]
    public function removePhoto(int $id, int $photoId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->findOwnedOr404($id, $user);

        $photo = $this->photosRepo->find($photoId);
        if ($photo === null || $photo->getListing()->getId() !== $listing->getId()) {
            return new JsonResponse(['error' => 'Photo introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $this->photoService->remove($photo);
        $listing->touchUpdatedAt();
        $this->em->flush();

        return new JsonResponse($this->serializeDetail($listing));
    }

    #[Route('/api/marketplace/listings/{id}/pause', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pause(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->findOwnedOr404($id, $user);
        $listing->setPausedAt(new \DateTimeImmutable());
        $this->em->flush();
        return new JsonResponse($this->serializeDetail($listing));
    }

    #[Route('/api/marketplace/listings/{id}/publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->findOwnedOr404($id, $user);
        $listing->setPausedAt(null);
        $this->em->flush();
        return new JsonResponse($this->serializeDetail($listing));
    }

    #[Route('/api/marketplace/listings/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->findOwnedOr404($id, $user);
        $this->photoService->removeAllFiles($listing);
        $this->em->remove($listing);
        $this->em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Charge une annonce et vérifie que le viewer courant en est
     * l'auteur — 404 sinon (masque l'existence à qui n'y a pas droit).
     */
    private function findOwnedOr404(int $id, User $viewer): MarketplaceListing
    {
        $listing = $this->listings->find($id);
        if ($listing === null || $listing->getAuthor()->getId() !== $viewer->getId()) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }
        return $listing;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(MarketplaceListing $l): array
    {
        $first = $l->getPhotos()->isEmpty() ? null : $l->getPhotos()->first();
        return [
            'id' => $l->getId(),
            'title' => $l->getTitle(),
            'authorFirstName' => $l->getAuthor()->getPrenom(),
            'createdAt' => $l->getCreatedAt()->format(\DATE_ATOM),
            'photoUrl' => $first instanceof MarketplaceListingPhoto ? $this->photoService->urlFor($first) : null,
            'photoCount' => $l->getPhotos()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(MarketplaceListing $l): array
    {
        $author = $l->getAuthor();
        return [
            'id' => $l->getId(),
            'title' => $l->getTitle(),
            'description' => $l->getDescription(),
            'authorId' => $author->getId(),
            'authorFirstName' => $author->getPrenom(),
            'authorFullName' => $author->getFullName(),
            'authorPhone' => $author->getTelephone(),
            'createdAt' => $l->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $l->getUpdatedAt()?->format(\DATE_ATOM),
            'paused' => $l->isPaused(),
            'photos' => array_map(fn (MarketplaceListingPhoto $p) => [
                'id' => $p->getId(),
                'url' => $this->photoService->urlFor($p),
            ], $l->getPhotos()->toArray()),
        ];
    }
}
