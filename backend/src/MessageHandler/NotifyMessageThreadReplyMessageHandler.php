<?php

namespace App\MessageHandler;

use App\Entity\MessageReply;
use App\Entity\User;
use App\Entity\UserMessage;
use App\Enum\MessageScope;
use App\Message\NotifyMessageThreadReplyMessage;
use App\Repository\MessageReplyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Notifie l'AUTRE partie d'une conversation quand un nouveau tour est
 * posté (au-delà du 2e échange verrouillé body/reply) :
 *  - auteur = expéditeur du message  → notifie le(s) destinataire(s)
 *    éligibles pour le scope (même résolution que
 *    NotifyNewUserMessageMessageHandler::resolveRecipients).
 *  - auteur = quelqu'un côté destinataire → notifie l'expéditeur
 *    (même contenu que NotifyUserMessageReplyMessageHandler).
 */
#[AsMessageHandler]
class NotifyMessageThreadReplyMessageHandler
{
    public function __construct(
        private readonly MessageReplyRepository $replies,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(NotifyMessageThreadReplyMessage $dispatched): void
    {
        $reply = $this->replies->find($dispatched->replyId);
        if ($reply === null) {
            return;
        }
        // Idempotence.
        if ($reply->getNotifiedAt() !== null) {
            return;
        }

        $msg = $reply->getMessage();
        $isFromSender = $reply->getAuthor()->getId() === $msg->getSender()->getId();
        $targets = $isFromSender ? $this->resolveRecipients($msg) : [$msg->getSender()];
        $targets = array_values(array_filter(
            $targets,
            fn (User $u) => $u->getEmail() !== null && $u->isActive() && $u->getId() !== $reply->getAuthor()->getId(),
        ));

        // Claim AVANT envoi — même stratégie que les autres handlers de
        // ce module (évite les doublons si le worker meurt mid-loop).
        $reply->setNotifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($targets === []) {
            return;
        }

        // Deep-link direct vers l'écran mobile de la conversation — la
        // suite d'un fil ne peut être poursuivie que depuis l'app (le
        // backend ne gère que la 1re réponse), donc même le staff est
        // renvoyé vers le mobile, pas vers /admin (jamais un vrai écran
        // « /profile/messages » côté mobile non plus).
        $threadUrl = rtrim($this->publicUrl, '/').($isFromSender ? '/contact/inbox/' : '/contact/sent/').$msg->getId();

        $sent = 0;
        $failed = 0;
        foreach ($targets as $target) {
            $email = (new TemplatedEmail())
                ->to($target->getEmail())
                ->subject(sprintf('%s a répondu dans votre conversation', $reply->getAuthor()->getFullName()))
                ->htmlTemplate('email/message_thread_reply.html.twig')
                ->textTemplate('email/message_thread_reply.txt.twig')
                ->context([
                    'target' => $target,
                    'message' => $msg,
                    'reply' => $reply,
                    'threadUrl' => $threadUrl,
                ]);
            try {
                $this->mailer->send($email);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $failed++;
                $this->logger->warning('Échec notif tour de conversation', [
                    'replyId' => $reply->getId(),
                    'targetId' => $target->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Notif tour de conversation envoyée', [
            'replyId' => $reply->getId(),
            'messageId' => $msg->getId(),
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    /**
     * Mêmes règles que NotifyNewUserMessageMessageHandler::resolveRecipients
     * — dupliqué volontairement (cohérent avec le reste du module, qui ne
     * factorise pas cette résolution en service partagé).
     *
     * @return list<User>
     */
    private function resolveRecipients(UserMessage $msg): array
    {
        switch ($msg->getScope()) {
            case MessageScope::Trainer:
                $recipient = $msg->getRecipient();
                if ($recipient === null || !$recipient->isActive() || $recipient->getEmail() === null) {
                    return [];
                }
                return [$recipient];
            case MessageScope::AllTrainers:
                return array_values(array_filter(
                    $this->users->findCoaches(),
                    fn (User $u) => $u->getEmail() !== null,
                ));
            case MessageScope::Club:
                return $this->users->findActiveByRole(User::ROLE_ADMIN);
        }
        return [];
    }
}
