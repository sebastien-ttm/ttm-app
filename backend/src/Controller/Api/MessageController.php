<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserMessage;
use App\Enum\MessageCategory;
use App\Enum\MessageScope;
use App\Enum\Profile;
use App\Message\NotifyNewUserMessageMessage;
use App\Message\NotifyUserMessageReplyMessage;
use App\Repository\UserMessageRecipientStateRepository;
use App\Repository\UserMessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class MessageController extends AbstractController
{
    public function __construct(
        private readonly UserMessageRepository $messages,
        private readonly UserMessageRecipientStateRepository $states,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Liste des entraîneurs sélectionnables comme destinataire d'un message.
     */
    #[Route('/api/me/trainers', methods: ['GET'])]
    public function trainers(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(
                fn (User $u) => [
                    'id' => $u->getId(),
                    'fullName' => $u->getFullName(),
                ],
                $this->messages->findSelectableTrainers(),
            ),
        ]);
    }

    // ============================================================
    //  Envoyés (côté expéditeur)
    // ============================================================

    /**
     * Mes messages envoyés. ?archived=1 renvoie les archivés à la
     * place des courants ; sans param, on renvoie les non-archivés.
     */
    #[Route('/api/me/messages', methods: ['GET'])]
    public function listMine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $archived = $request->query->getBoolean('archived');
        return new JsonResponse([
            'data' => array_map(
                fn (UserMessage $m) => $this->serializeMessage($m),
                $this->messages->findSentBy($user, $archived),
            ),
        ]);
    }

    /**
     * Envoi. Body : { scope: 'club'|'trainer'|'all_trainers',
     *                 recipientId?: int, subject?: string, body: string }
     * Rétro-compat : si scope absent, on infère (recipientId → trainer,
     * sinon club) — comportement identique à l'ancienne API.
     */
    #[Route('/api/me/messages', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            return new JsonResponse(['error' => 'Le message ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($body) > 5000) {
            return new JsonResponse(['error' => 'Message trop long (5000 caractères max).'], Response::HTTP_BAD_REQUEST);
        }

        $subject = isset($payload['subject']) ? trim((string) $payload['subject']) : '';
        if (mb_strlen($subject) > 200) {
            return new JsonResponse(['error' => 'Objet trop long (200 caractères max).'], Response::HTTP_BAD_REQUEST);
        }

        $recipientId = isset($payload['recipientId']) ? (int) $payload['recipientId'] : 0;
        $scope = $this->resolveScope($payload['scope'] ?? null, $recipientId);
        if ($scope === null) {
            return new JsonResponse(['error' => 'scope invalide (club|trainer|all_trainers).'], Response::HTTP_BAD_REQUEST);
        }

        $rawCategory = $payload['category'] ?? null;
        $category = MessageCategory::General;
        if (is_string($rawCategory) && $rawCategory !== '') {
            $parsed = MessageCategory::tryFrom($rawCategory);
            if ($parsed === null) {
                return new JsonResponse(['error' => 'category invalide.'], Response::HTTP_BAD_REQUEST);
            }
            $category = $parsed;
        }

        $recipient = null;
        if ($scope === MessageScope::Trainer) {
            if ($recipientId <= 0) {
                return new JsonResponse(['error' => 'recipientId requis pour scope=trainer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $recipient = $this->users->find($recipientId);
            if ($recipient === null || !$recipient->isActive()) {
                return new JsonResponse(['error' => 'Destinataire introuvable.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!in_array(Profile::Entraineur->value, $recipient->getProfiles(), true)) {
                return new JsonResponse(['error' => 'Le destinataire doit être un entraîneur.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $msg = new UserMessage();
        $msg->setSender($user);
        $msg->setRecipient($recipient);
        $msg->setScope($scope);
        $msg->setCategory($category);
        $msg->setSubject($subject !== '' ? $subject : null);
        $msg->setBody($body);

        $this->em->persist($msg);
        $this->em->flush();

        if ($msg->getId() !== null) {
            $this->bus->dispatch(new NotifyNewUserMessageMessage($msg->getId()));
        }

        return new JsonResponse($this->serializeMessage($msg), Response::HTTP_CREATED);
    }

    /**
     * Archive / désarchive un message DONT je suis l'expéditeur.
     */
    #[Route('/api/me/messages/{id}/archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archiveSent(int $id): JsonResponse
    {
        return $this->toggleSenderArchive($id, true);
    }

    #[Route('/api/me/messages/{id}/unarchive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unarchiveSent(int $id): JsonResponse
    {
        return $this->toggleSenderArchive($id, false);
    }

    private function toggleSenderArchive(int $id, bool $archive): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $msg = $this->messages->find($id);
        if ($msg === null) {
            return new JsonResponse(['error' => 'Message introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($msg->getSender()->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Vous n\'êtes pas l\'expéditeur de ce message.'], Response::HTTP_FORBIDDEN);
        }
        $msg->setSenderArchivedAt($archive ? new \DateTimeImmutable() : null);
        $this->em->flush();
        return new JsonResponse(['ok' => true, 'senderArchivedAt' => $msg->getSenderArchivedAt()?->format(\DATE_ATOM)]);
    }

    // ============================================================
    //  Reçus (côté destinataire — entraîneurs & admins)
    // ============================================================

    /**
     * Boîte de réception : messages visibles par le viewer (scope=club
     * s'il est admin, scope=all_trainers s'il est entraîneur, scope=trainer
     * s'il en est le destinataire nommé).
     *
     * ?archived=1 = les messages que J'AI archivés dans MA boîte
     * (indépendant des collègues destinataires du même message).
     */
    #[Route('/api/me/inbox', methods: ['GET'])]
    public function inbox(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $archived = $request->query->getBoolean('archived');

        $rows = $this->messages->findInboxFor($viewer);
        if ($rows === []) {
            return new JsonResponse(['data' => []]);
        }

        $ids = array_map(fn (UserMessage $m) => (int) $m->getId(), $rows);
        $states = $this->states->findByUserIndexedByMessageId($viewer, $ids);

        $out = [];
        foreach ($rows as $m) {
            $state = $states[$m->getId()] ?? null;
            $isArchived = $state?->isArchived() ?? false;
            if ($isArchived !== $archived) {
                continue;
            }
            $out[] = $this->serializeInbox($m, $viewer, $state?->getArchivedAt());
        }
        return new JsonResponse(['data' => $out]);
    }

    /**
     * Répondre à un message reçu. Verrouillé après première réponse
     * (setReplyOnce) — les collègues destinataires voient la réponse
     * + l'auteur mais ne peuvent pas re-répondre.
     */
    #[Route('/api/me/inbox/{id}/reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $msg = $this->messages->find($id);
        if ($msg === null || !$this->canView($msg, $viewer)) {
            return new JsonResponse(['error' => 'Message introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($msg->hasReply()) {
            return new JsonResponse(['error' => 'Une réponse a déjà été postée par '.($msg->getRepliedBy()?->getFullName() ?? 'un collègue').'.'], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true);
        $reply = is_array($payload) ? trim((string) ($payload['reply'] ?? '')) : '';
        if ($reply === '') {
            return new JsonResponse(['error' => 'La réponse ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($reply) > 5000) {
            return new JsonResponse(['error' => 'Réponse trop longue (5000 caractères max).'], Response::HTTP_BAD_REQUEST);
        }

        $accepted = $msg->setReplyOnce($reply, $viewer);
        if (!$accepted) {
            return new JsonResponse(['error' => 'Réponse refusée.'], Response::HTTP_CONFLICT);
        }
        $this->em->flush();

        if ($msg->getId() !== null) {
            $this->bus->dispatch(new NotifyUserMessageReplyMessage($msg->getId()));
        }

        return new JsonResponse([
            'ok' => true,
            'message' => $this->serializeInbox($msg, $viewer, null),
        ]);
    }

    #[Route('/api/me/inbox/{id}/archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archiveInbox(int $id): JsonResponse
    {
        return $this->toggleInboxArchive($id, true);
    }

    #[Route('/api/me/inbox/{id}/unarchive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unarchiveInbox(int $id): JsonResponse
    {
        return $this->toggleInboxArchive($id, false);
    }

    private function toggleInboxArchive(int $id, bool $archive): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $msg = $this->messages->find($id);
        if ($msg === null || !$this->canView($msg, $viewer)) {
            return new JsonResponse(['error' => 'Message introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $state = $this->states->findOrCreate($viewer, $msg);
        $state->setArchivedAt($archive ? new \DateTimeImmutable() : null);
        if ($state->getId() === null) {
            $this->em->persist($state);
        }
        $this->em->flush();
        return new JsonResponse([
            'ok' => true,
            'archivedAt' => $state->getArchivedAt()?->format(\DATE_ATOM),
        ]);
    }

    // ============================================================
    //  Helpers
    // ============================================================

    private function resolveScope(mixed $rawScope, int $recipientId): ?MessageScope
    {
        if (is_string($rawScope) && $rawScope !== '') {
            return MessageScope::tryFrom($rawScope);
        }
        // Rétro-compat : ancien mobile qui n'envoie pas de scope.
        return $recipientId > 0 ? MessageScope::Trainer : MessageScope::Club;
    }

    /**
     * True si le viewer a le droit de voir ce message en tant que
     * destinataire (mêmes règles que findInboxFor, appliquées à un
     * seul message).
     */
    private function canView(UserMessage $msg, User $viewer): bool
    {
        return match ($msg->getScope()) {
            MessageScope::Club => $viewer->isAdmin(),
            MessageScope::AllTrainers => $viewer->isEntraineur(),
            MessageScope::Trainer => $msg->getRecipient()?->getId() === $viewer->getId(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(UserMessage $m): array
    {
        return [
            'id' => $m->getId(),
            'scope' => $m->getScope()->value,
            'category' => $m->getCategory()->value,
            'categoryLabel' => $m->getCategory()->label(),
            'categoryIcon' => $m->getCategory()->icon(),
            'recipientId' => $m->getRecipient()?->getId(),
            'recipientLabel' => $m->getRecipientLabel(),
            'subject' => $m->getSubject(),
            'body' => $m->getBody(),
            'sentAt' => $m->getSentAt()->format(\DATE_ATOM),
            'reply' => $m->getReply(),
            'repliedAt' => $m->getRepliedAt()?->format(\DATE_ATOM),
            'repliedByLabel' => $m->getRepliedBy()?->getFullName(),
            'hasReply' => $m->hasReply(),
            'senderArchivedAt' => $m->getSenderArchivedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * Sérialise un message pour la vue « inbox » : idem sender-side +
     * infos expéditeur + état d'archivage personnel du viewer +
     * indication de la portée pour l'UI (« pour vous seul », etc.).
     *
     * @return array<string, mixed>
     */
    private function serializeInbox(UserMessage $m, User $viewer, ?\DateTimeImmutable $myArchivedAt): array
    {
        $scope = $m->getScope();
        $scopeLabel = match ($scope) {
            MessageScope::Trainer => 'Pour vous seul',
            MessageScope::AllTrainers => 'Pour tous les entraîneurs',
            MessageScope::Club => 'Pour le club (admins)',
        };
        return [
            'id' => $m->getId(),
            'scope' => $scope->value,
            'scopeLabel' => $scopeLabel,
            'category' => $m->getCategory()->value,
            'categoryLabel' => $m->getCategory()->label(),
            'categoryIcon' => $m->getCategory()->icon(),
            'senderId' => $m->getSender()->getId(),
            'senderLabel' => $m->getSender()->getFullName(),
            'subject' => $m->getSubject(),
            'body' => $m->getBody(),
            'sentAt' => $m->getSentAt()->format(\DATE_ATOM),
            'reply' => $m->getReply(),
            'repliedAt' => $m->getRepliedAt()?->format(\DATE_ATOM),
            'repliedById' => $m->getRepliedBy()?->getId(),
            'repliedByLabel' => $m->getRepliedBy()?->getFullName(),
            'hasReply' => $m->hasReply(),
            'canReply' => !$m->hasReply(),
            'myArchivedAt' => $myArchivedAt?->format(\DATE_ATOM),
        ];
    }
}
