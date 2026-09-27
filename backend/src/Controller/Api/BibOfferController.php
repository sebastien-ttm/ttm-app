<?php

namespace App\Controller\Api;

use App\Entity\BibOffer;
use App\Entity\User;
use App\Repository\BibOfferRepository;
use App\Repository\MarketplaceConversationRepository;
use App\Security\MarketplaceAccessVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bourse aux dossards (onglet Social) : un adhérent qui ne peut plus
 * participer propose ses dossards, en don ou en revente. Le contact passe
 * par les discussions de la bourse (POST /api/bibs/{id}/conversation,
 * voir MarketplaceConversationController).
 *
 * Mêmes conditions d'accès que la bourse aux équipements (phase de test).
 */
#[IsGranted(MarketplaceAccessVoter::ATTRIBUTE)]
class BibOfferController extends AbstractController
{
    private const MAX_PRICE_CENTS = 100_000; // 1 000 € par dossard

    public function __construct(
        private readonly BibOfferRepository $offers,
        private readonly MarketplaceConversationRepository $conversations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Offres publiées pour des courses à venir, la plus proche d'abord. */
    #[Route('/api/bibs', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (BibOffer $o) => $this->serialize($o), $this->offers->findPublishedUpcoming()),
        ]);
    }

    /** Mes offres (publiées, en pause, passées) — gestion perso. */
    #[Route('/api/bibs/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse([
            'data' => array_map(fn (BibOffer $o) => $this->serialize($o), $this->offers->findAllByAuthor($user)),
        ]);
    }

    /** Détail. Une offre en pause n'est visible que par son auteur (404 sinon). */
    #[Route('/api/bibs/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $offer = $this->offers->find($id);
        if ($offer === null || ($offer->isPaused() && $offer->getAuthor()->getId() !== $user->getId())) {
            throw $this->createNotFoundException('Offre introuvable.');
        }

        $data = $this->serialize($offer);
        $data['myConversationId'] = $offer->getAuthor()->getId() === $user->getId()
            ? null
            : $this->conversations->findOneByBibOfferAndBuyer($offer, $user)?->getId();
        return new JsonResponse($data);
    }

    /**
     * Création. JSON : raceName, raceDate (AAAA-MM-JJ), quantity,
     * exchangeType ('don'|'revente'), unitPrice (euros, revente),
     * negotiable (revente), description?.
     */
    #[Route('/api/bibs', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $offer = new BibOffer($user);
        $error = $this->apply($offer, $payload, true);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $this->em->persist($offer);
        $this->em->flush();

        return new JsonResponse($this->serialize($offer), Response::HTTP_CREATED);
    }

    #[Route('/api/bibs/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $offer = $this->findOwnedOr404($id, $user);
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->apply($offer, $payload, false);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $offer->touchUpdatedAt();
        $this->em->flush();

        return new JsonResponse($this->serialize($offer));
    }

    #[Route('/api/bibs/{id}/pause', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pause(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $offer = $this->findOwnedOr404($id, $user);
        $offer->setPausedAt(new \DateTimeImmutable());
        $this->em->flush();
        return new JsonResponse($this->serialize($offer));
    }

    #[Route('/api/bibs/{id}/publish', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publish(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $offer = $this->findOwnedOr404($id, $user);
        $offer->setPausedAt(null);
        $this->em->flush();
        return new JsonResponse($this->serialize($offer));
    }

    #[Route('/api/bibs/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->em->remove($this->findOwnedOr404($id, $user));
        $this->em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Applique le payload (création : tout est requis ; édition : seuls
     * les champs fournis changent). Retourne un message d'erreur ou null.
     *
     * @param array<string, mixed> $payload
     */
    private function apply(BibOffer $offer, array $payload, bool $creating): ?string
    {
        if ($creating || array_key_exists('raceName', $payload)) {
            $name = trim((string) ($payload['raceName'] ?? ''));
            if ($name === '') {
                return 'Le nom de la course ne peut pas être vide.';
            }
            if (mb_strlen($name) > 150) {
                return 'Nom trop long (150 caractères max).';
            }
            $offer->setRaceName($name);
        }

        if ($creating || array_key_exists('raceDate', $payload)) {
            $raw = (string) ($payload['raceDate'] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($date === false || $date->format('Y-m-d') !== $raw) {
                return 'Date de la course invalide.';
            }
            if ($date < new \DateTimeImmutable('today')) {
                return 'La date de la course est déjà passée.';
            }
            $offer->setRaceDate($date);
        }

        if ($creating || array_key_exists('quantity', $payload)) {
            $q = filter_var($payload['quantity'] ?? null, \FILTER_VALIDATE_INT);
            if ($q === false || $q < 1 || $q > BibOffer::MAX_QUANTITY) {
                return sprintf('Nombre de dossards invalide (1 à %d).', BibOffer::MAX_QUANTITY);
            }
            $offer->setQuantity($q);
        }

        if ($creating || array_key_exists('exchangeType', $payload)
            || array_key_exists('unitPrice', $payload) || array_key_exists('negotiable', $payload)) {
            $type = (string) ($payload['exchangeType'] ?? $offer->getExchangeType());
            if (!in_array($type, BibOffer::EXCHANGES, true)) {
                return 'Type d\'échange invalide (don ou revente).';
            }
            $negotiable = array_key_exists('negotiable', $payload)
                ? (bool) $payload['negotiable']
                : $offer->isNegotiable();

            $priceCents = $offer->getUnitPriceCents();
            if (array_key_exists('unitPrice', $payload)) {
                $priceCents = null;
                $rawPrice = trim(str_replace(',', '.', (string) ($payload['unitPrice'] ?? '')));
                if ($rawPrice !== '') {
                    if (!is_numeric($rawPrice) || (float) $rawPrice < 0) {
                        return 'Prix unitaire invalide.';
                    }
                    $priceCents = (int) round((float) $rawPrice * 100);
                    if ($priceCents > self::MAX_PRICE_CENTS) {
                        return 'Prix unitaire trop élevé.';
                    }
                }
            }
            if ($type === BibOffer::EXCHANGE_REVENTE && $priceCents === null && !$negotiable) {
                return 'Indiquez un prix unitaire ou cochez « à négocier ».';
            }
            $offer->setExchange($type, $priceCents, $negotiable);
        }

        if (array_key_exists('description', $payload)) {
            $d = trim((string) ($payload['description'] ?? ''));
            if (mb_strlen($d) > 1000) {
                return 'Précisions trop longues (1000 caractères max).';
            }
            $offer->setDescription($d);
        }

        return null;
    }

    private function findOwnedOr404(int $id, User $viewer): BibOffer
    {
        $offer = $this->offers->find($id);
        if ($offer === null || $offer->getAuthor()->getId() !== $viewer->getId()) {
            throw $this->createNotFoundException('Offre introuvable.');
        }
        return $offer;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(BibOffer $o): array
    {
        $author = $o->getAuthor();
        return [
            'id' => $o->getId(),
            'raceName' => $o->getRaceName(),
            'raceDate' => $o->getRaceDate()->format('Y-m-d'),
            'quantity' => $o->getQuantity(),
            'exchangeType' => $o->getExchangeType(),
            'unitPriceCents' => $o->getUnitPriceCents(),
            'negotiable' => $o->isNegotiable(),
            'description' => $o->getDescription(),
            'authorId' => $author->getId(),
            'authorFirstName' => $author->getPrenom(),
            'authorFullName' => $author->getFullName(),
            'createdAt' => $o->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $o->getUpdatedAt()?->format(\DATE_ATOM),
            'paused' => $o->isPaused(),
            'past' => $o->getRaceDate() < new \DateTimeImmutable('today'),
        ];
    }
}
