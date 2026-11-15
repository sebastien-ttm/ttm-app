<?php

namespace App\Controller\Api;

use App\Entity\MarketplaceListing;
use App\Entity\MarketplaceListingLike;
use App\Entity\MarketplaceListingPhoto;
use App\Entity\User;
use App\Repository\MarketplaceConversationRepository;
use App\Repository\MarketplaceListingLikeRepository;
use App\Repository\MarketplaceListingPhotoRepository;
use App\Repository\MarketplaceListingRepository;
use App\Security\MarketplaceAccessVoter;
use App\Service\Marketplace\MarketplaceListingPhotoService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bourse aux équipements (onglet Club) : annonces d'adhérents pour du
 * matériel/des affaires d'occasion. Le contact se fait par messages dans
 * l'application (voir MarketplaceConversationController) — aucun numéro
 * de téléphone n'est exposé.
 *
 * Phase de test : réservée aux comptes listés dans
 * MARKETPLACE_TESTER_EMAILS (voir MarketplaceAccess) — 403 pour les autres.
 */
#[IsGranted(MarketplaceAccessVoter::ATTRIBUTE)]
class MarketplaceController extends AbstractController
{
    public function __construct(
        private readonly MarketplaceListingRepository $listings,
        private readonly MarketplaceListingPhotoRepository $photosRepo,
        private readonly MarketplaceConversationRepository $conversations,
        private readonly MarketplaceListingLikeRepository $likes,
        private readonly MarketplaceListingPhotoService $photoService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Annonces publiées, plus récentes d'abord — visible de tout le club. S'y ajoutent, à la suite, les annonces que
     * le viewer aime et qui ne sont plus proposées au club (expirées ou en pause) : il les garde tant que leur auteur
     * ne les a pas supprimées (`liked`, `expired`, `paused` permettent à l'appli de les présenter à part).
     */
    #[Route('/api/marketplace/listings', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $likedIds = array_flip($this->likes->findListingIdsByUser($user));

        $rows = $this->listings->findPublished();
        $listed = [];
        foreach ($rows as $l) {
            $listed[$l->getId()] = true;
        }
        foreach ($this->listings->findLikedBy($user) as $l) {
            if (!isset($listed[$l->getId()])) {
                $rows[] = $l;
            }
        }

        return new JsonResponse([
            'data' => array_map(fn (MarketplaceListing $l) => $this->serializeSummary($l, isset($likedIds[$l->getId()])), $rows),
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
     * Détail d'une annonce. Une annonce en pause ou expirée (plus de 10 jours sans réactivation) n'est visible que
     * par son auteur (pour prévisualiser/gérer/réactiver) et par ceux qui l'ont aimée (ils la gardent tant qu'elle n'est
     * pas supprimée) — 404 sinon, pas de 403 qui laisserait deviner l'existence.
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
        $isAuthor = $listing->getAuthor()->getId() === $user->getId();
        $liked = !$isAuthor && $this->likes->findOneByListingAndUser($listing, $user) !== null;
        if (!$listing->isActive() && !$isAuthor && !$liked) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }

        $data = $this->serializeDetail($listing);
        // « J'aime » du viewer (toujours false pour l'auteur).
        $data['liked'] = $liked;
        // Discussion déjà ouverte par le viewer avec le vendeur (null pour
        // l'auteur lui-même, qui passe par sa liste de discussions).
        $data['myConversationId'] = $listing->getAuthor()->getId() === $user->getId()
            ? null
            : $this->conversations->findOneByListingAndBuyer($listing, $user)?->getId();
        return new JsonResponse($data);
    }

    /**
     * Création. Multipart : title, description, price (facultatif, en euros), photos[] (0 à 5 fichiers).
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

        $price = self::parsePrice($request->request->get('price'), $invalidPrice);
        if ($invalidPrice) {
            return new JsonResponse(['error' => 'Prix invalide (un montant en euros, ex : 45 ou 45,50).'], Response::HTTP_BAD_REQUEST);
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
        $listing->setPrice($price);
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
        if (array_key_exists('price', $payload)) {
            $price = self::parsePrice($payload['price'], $invalidPrice);
            if ($invalidPrice) {
                return new JsonResponse(['error' => 'Prix invalide (un montant en euros, ex : 45 ou 45,50).'], Response::HTTP_BAD_REQUEST);
            }
            $listing->setPrice($price);
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

    /**
     * Publie (ou réactive) l'annonce : sort de la pause ET relance les 10 jours de visibilité — c'est la seule façon
     * de remettre dans la liste une annonce expirée.
     */
    #[Route('/api/marketplace/listings/{id}/publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->findOwnedOr404($id, $user);
        $listing->republish();
        $this->em->flush();
        return new JsonResponse($this->serializeDetail($listing));
    }

    /**
     * « J'aime » : garde l'annonce dans la liste du viewer tant que son auteur ne l'a pas supprimée, même quand elle
     * expire ou passe en pause. On n'aime que ce qui est proposé au club (annonce active) ; le faire deux fois est sans
     * effet. L'auteur ne peut pas aimer sa propre annonce.
     */
    #[Route('/api/marketplace/listings/{id}/like', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function like(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->listings->find($id);
        if ($listing === null) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }
        if ($this->likes->findOneByListingAndUser($listing, $user) !== null) {
            return new JsonResponse(['liked' => true]);
        }
        if (!$listing->isActive()) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }
        if ($listing->getAuthor()->getId() === $user->getId()) {
            return new JsonResponse(['error' => 'Vous ne pouvez pas aimer votre propre annonce.'], Response::HTTP_CONFLICT);
        }

        try {
            $this->em->persist(new MarketplaceListingLike($listing, $user));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux appuis simultanés : l'annonce est déjà aimée.
        }

        return new JsonResponse(['liked' => true]);
    }

    /** Retire son « J'aime » (sans effet s'il n'y en a pas). Une annonce expirée ou en pause disparaît alors de sa liste. */
    #[Route('/api/marketplace/listings/{id}/like', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unlike(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $listing = $this->listings->find($id);
        if ($listing === null) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }
        $like = $this->likes->findOneByListingAndUser($listing, $user);
        if ($like !== null) {
            $this->em->remove($like);
            $this->em->flush();
        }

        return new JsonResponse(['liked' => false]);
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
    private function serializeSummary(MarketplaceListing $l, bool $liked = false): array
    {
        $first = $l->getPhotos()->isEmpty() ? null : $l->getPhotos()->first();
        return [
            // J'aime du viewer ; une annonce aimée reste dans sa liste même expirée ou en pause (voir list()).
            'liked' => $liked,
            'paused' => $l->isPaused(),
            'expired' => $l->isExpired(),
            'id' => $l->getId(),
            'title' => $l->getTitle(),
            'authorId' => $l->getAuthor()->getId(),
            'authorFirstName' => $l->getAuthor()->getPrenom(),
            'authorFullName' => $l->getAuthor()->getFullName(),
            'createdAt' => $l->getCreatedAt()->format(\DATE_ATOM),
            // Mise en ligne ou dernière réactivation (ordre de la liste).
            'publishedAt' => $l->getPublishedAt()->format(\DATE_ATOM),
            // Prix en euros ; null = non renseigné.
            'price' => $l->getPrice(),
            'photoUrl' => $first instanceof MarketplaceListingPhoto ? $this->photoService->urlFor($first) : null,
            'photoCount' => $l->getPhotos()->count(),
        ];
    }

    /**
     * Prix saisi : « 45 », « 45,50 », « 45.5 € » (espaces et symbole € tolérés) ; vide ou null = pas de prix.
     * `$invalid` passe à true si la saisie n'est pas un montant valide (positif, 2 décimales au plus).
     */
    private static function parsePrice(mixed $raw, ?bool &$invalid = null): ?float
    {
        $invalid = false;
        if ($raw === null || is_array($raw)) {
            $invalid = is_array($raw);

            return null;
        }
        $text = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}\x{20AC}]/u', '', (string) $raw));
        if ($text === '') {
            return null;
        }
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $text)) {
            $invalid = true;

            return null;
        }

        return (float) $text;
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
            'createdAt' => $l->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $l->getUpdatedAt()?->format(\DATE_ATOM),
            'publishedAt' => $l->getPublishedAt()->format(\DATE_ATOM),
            // Prix en euros ; null = non renseigné.
            'price' => $l->getPrice(),
            'paused' => $l->isPaused(),
            // Expirée : plus de 10 jours depuis la mise en ligne / réactivation — seul l'auteur peut la réactiver.
            'expired' => $l->isExpired(),
            'expiresAt' => $l->getExpiresAt()->format(\DATE_ATOM),
            'photos' => array_map(fn (MarketplaceListingPhoto $p) => [
                'id' => $p->getId(),
                'url' => $this->photoService->urlFor($p),
            ], $l->getPhotos()->toArray()),
        ];
    }
}
