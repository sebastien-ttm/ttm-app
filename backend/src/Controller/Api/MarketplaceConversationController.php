<?php

namespace App\Controller\Api;

use App\Entity\MarketplaceConversation;
use App\Entity\MarketplaceListingPhoto;
use App\Entity\MarketplaceMessage;
use App\Entity\User;
use App\Message\NotifyMarketplaceMessageMessage;
use App\Repository\MarketplaceConversationRepository;
use App\Repository\MarketplaceListingRepository;
use App\Repository\MarketplaceMessageRepository;
use App\Security\MarketplaceAccessVoter;
use App\Service\Marketplace\MarketplaceListingPhotoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Discussions de la bourse aux équipements : un acheteur potentiel écrit
 * au vendeur (auteur de l'annonce), qui répond, sans limite de tours.
 * Chaque message déclenche un e-mail à l'interlocuteur (voir
 * NotifyMarketplaceMessageMessageHandler).
 *
 * Mêmes conditions d'accès que le reste de la bourse (phase de test).
 */
#[IsGranted(MarketplaceAccessVoter::ATTRIBUTE)]
class MarketplaceConversationController extends AbstractController
{
    private const MAX_LENGTH = 2000;
    /** Anti-spam : chaque message = 1 e-mail chez le destinataire. */
    private const MAX_MESSAGES_PER_HOUR = 30;

    public function __construct(
        private readonly MarketplaceConversationRepository $conversations,
        private readonly MarketplaceMessageRepository $messages,
        private readonly MarketplaceListingRepository $listings,
        private readonly MarketplaceListingPhotoService $photoService,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Mes discussions (en tant qu'acheteur OU vendeur), la plus récente d'abord.
     */
    #[Route('/api/marketplace/conversations', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $convs = $this->conversations->findForUser($user);
        $last = $this->messages->findLastByConversations($convs);

        return new JsonResponse([
            'data' => array_map(
                fn (MarketplaceConversation $c) => $this->serializeSummary($c, $user, $last[$c->getId()] ?? null),
                $convs,
            ),
        ]);
    }

    #[Route('/api/marketplace/conversations/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse($this->serializeDetail($this->findParticipantOr404($id, $user), $user));
    }

    /**
     * Premier message à l'auteur d'une annonce : crée la discussion (ou
     * reprend celle qui existe déjà entre ces deux personnes pour cette
     * annonce). Body : { content }.
     */
    #[Route('/api/marketplace/listings/{id}/conversation', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $listing = $this->listings->find($id);
        // Annonce en pause : invisible pour les autres, donc injoignable.
        if ($listing === null || ($listing->isPaused() && $listing->getAuthor()->getId() !== $user->getId())) {
            throw $this->createNotFoundException('Annonce introuvable.');
        }
        if ($listing->getAuthor()->getId() === $user->getId()) {
            return new JsonResponse(['error' => 'Vous ne pouvez pas vous écrire à propos de votre propre annonce.'], Response::HTTP_CONFLICT);
        }

        $content = $this->readContent($request, $user);
        if ($content instanceof JsonResponse) {
            return $content;
        }

        $conversation = $this->conversations->findOneByListingAndBuyer($listing, $user);
        if ($conversation === null) {
            $conversation = new MarketplaceConversation($listing, $user);
            $this->em->persist($conversation);
        }
        $this->appendMessage($conversation, $user, $content);

        return new JsonResponse($this->serializeDetail($conversation, $user), Response::HTTP_CREATED);
    }

    /**
     * Répondre dans une discussion existante (l'un ou l'autre participant).
     * Body : { content }.
     */
    #[Route('/api/marketplace/conversations/{id}/messages', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $conversation = $this->findParticipantOr404($id, $user);

        $content = $this->readContent($request, $user);
        if ($content instanceof JsonResponse) {
            return $content;
        }
        $message = $this->appendMessage($conversation, $user, $content);

        return new JsonResponse(['ok' => true, 'message' => $this->serializeMessage($message, $user)], Response::HTTP_CREATED);
    }

    private function appendMessage(MarketplaceConversation $conversation, User $author, string $content): MarketplaceMessage
    {
        $message = new MarketplaceMessage($conversation, $author, $content);
        $this->em->persist($message);
        $conversation->touchLastMessageAt();
        $this->em->flush();

        // Un e-mail au destinataire pour CHAQUE message (async, idempotent).
        $this->bus->dispatch(new NotifyMarketplaceMessageMessage($message->getId()));

        return $message;
    }

    /**
     * Lit et valide le texte du message : non vide, 2000 caractères max,
     * et plafond horaire par auteur (anti-spam de boîtes mail).
     */
    private function readContent(Request $request, User $author): string|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $content = is_array($payload) ? trim((string) ($payload['content'] ?? '')) : '';
        if ($content === '') {
            return new JsonResponse(['error' => 'Le message ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($content) > self::MAX_LENGTH) {
            return new JsonResponse(
                ['error' => sprintf('Message trop long (%d caractères max).', self::MAX_LENGTH)],
                Response::HTTP_BAD_REQUEST,
            );
        }
        if ($this->messages->countByAuthorSince($author, new \DateTimeImmutable('-1 hour')) >= self::MAX_MESSAGES_PER_HOUR) {
            return new JsonResponse(
                ['error' => 'Trop de messages envoyés en peu de temps. Réessayez dans un moment.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }
        return $content;
    }

    /** 404 (et non 403) pour qui n'est pas dans la discussion : masque son existence. */
    private function findParticipantOr404(int $id, User $viewer): MarketplaceConversation
    {
        $conversation = $this->conversations->find($id);
        if ($conversation === null || !$conversation->isParticipant($viewer)) {
            throw $this->createNotFoundException('Discussion introuvable.');
        }
        return $conversation;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(MarketplaceConversation $c, User $viewer, ?MarketplaceMessage $last): array
    {
        $listing = $c->getListing();
        $other = $c->getOtherParticipant($viewer);
        $first = $listing->getPhotos()->isEmpty() ? null : $listing->getPhotos()->first();
        return [
            'id' => $c->getId(),
            'listingId' => $listing->getId(),
            'listingTitle' => $listing->getTitle(),
            'listingPhotoUrl' => $first instanceof MarketplaceListingPhoto ? $this->photoService->urlFor($first) : null,
            'iAmSeller' => $c->getSeller()->getId() === $viewer->getId(),
            'otherFirstName' => $other->getPrenom(),
            'lastMessage' => $last === null ? null : [
                'content' => mb_strimwidth($last->getContent(), 0, 140, '…'),
                'mine' => $last->getAuthor()->getId() === $viewer->getId(),
                'createdAt' => $last->getCreatedAt()->format(\DATE_ATOM),
            ],
            'lastMessageAt' => $c->getLastMessageAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(MarketplaceConversation $c, User $viewer): array
    {
        $listing = $c->getListing();
        $other = $c->getOtherParticipant($viewer);
        return [
            'id' => $c->getId(),
            'listingId' => $listing->getId(),
            'listingTitle' => $listing->getTitle(),
            'listingPaused' => $listing->isPaused(),
            'iAmSeller' => $c->getSeller()->getId() === $viewer->getId(),
            'otherFirstName' => $other->getPrenom(),
            'otherFullName' => $other->getFullName(),
            // Requête directe plutôt que $c->getMessages() : sur une
            // conversation tout juste créée, la collection en mémoire ne
            // contient pas encore le message qu'on vient de flusher.
            'messages' => array_map(
                fn (MarketplaceMessage $m) => $this->serializeMessage($m, $viewer),
                $this->messages->findBy(['conversation' => $c], ['createdAt' => 'ASC', 'id' => 'ASC']),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(MarketplaceMessage $m, User $viewer): array
    {
        return [
            'id' => $m->getId(),
            'mine' => $m->getAuthor()->getId() === $viewer->getId(),
            'authorFirstName' => $m->getAuthor()->getPrenom(),
            'content' => $m->getContent(),
            'createdAt' => $m->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
