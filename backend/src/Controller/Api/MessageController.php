<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserMessage;
use App\Enum\Profile;
use App\Message\NotifyNewUserMessageMessage;
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
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Liste des entraîneurs sélectionnables comme destinataire d'un message.
     * Pratique : la première option de l'UI mobile est « Le club » (recipientId=null).
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

    /**
     * Mes messages envoyés (avec leurs réponses si elles existent).
     */
    #[Route('/api/me/messages', methods: ['GET'])]
    public function listMine(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse([
            'data' => array_map(
                fn (UserMessage $m) => $this->serializeMessage($m),
                $this->messages->findSentBy($user),
            ),
        ]);
    }

    /**
     * Envoyer un nouveau message.
     *
     * Body : { recipientId?: int, subject?: string, body: string }
     *
     * recipientId null/absent = adressé « au club » (visible aux admins).
     * recipientId fourni : doit pointer sur un entraîneur actif (Profile.Entraineur).
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

        $recipient = null;
        $recipientId = isset($payload['recipientId']) ? (int) $payload['recipientId'] : 0;
        if ($recipientId > 0) {
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
        $msg->setSubject($subject !== '' ? $subject : null);
        $msg->setBody($body);

        $this->em->persist($msg);
        $this->em->flush();

        // Notification email aux destinataires (admins ou entraîneur ciblé).
        // Handler async + idempotent via UserMessage::recipientsNotifiedAt.
        if ($msg->getId() !== null) {
            $this->bus->dispatch(new NotifyNewUserMessageMessage($msg->getId()));
        }

        return new JsonResponse($this->serializeMessage($msg), Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(UserMessage $m): array
    {
        return [
            'id' => $m->getId(),
            'recipientId' => $m->getRecipient()?->getId(),
            'recipientLabel' => $m->getRecipientLabel(),
            'subject' => $m->getSubject(),
            'body' => $m->getBody(),
            'sentAt' => $m->getSentAt()->format(\DATE_ATOM),
            'reply' => $m->getReply(),
            'repliedAt' => $m->getRepliedAt()?->format(\DATE_ATOM),
            'repliedByLabel' => $m->getRepliedBy()?->getFullName(),
            'hasReply' => $m->hasReply(),
        ];
    }
}
