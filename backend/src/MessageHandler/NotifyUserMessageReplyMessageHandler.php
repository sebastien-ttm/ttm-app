<?php

namespace App\MessageHandler;

use App\Message\NotifyUserMessageReplyMessage;
use App\Repository\UserMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NotifyUserMessageReplyMessageHandler
{
    public function __construct(
        private readonly UserMessageRepository $messages,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(NotifyUserMessageReplyMessage $message): void
    {
        $msg = $this->messages->find($message->messageId);
        if ($msg === null) {
            return;
        }
        if (!$msg->hasReply()) {
            // Cas pathologique : message dispatché alors qu'aucune réponse
            // n'est posée. Skip silencieux pour ne pas boucler.
            return;
        }
        // Idempotence
        if ($msg->getSenderRepliedNotifiedAt() !== null) {
            return;
        }

        $sender = $msg->getSender();
        if ($sender->getEmail() === null || !$sender->isActive()) {
            // L'expéditeur n'a plus de mail valide : on marque comme
            // notifié pour ne pas réessayer indéfiniment.
            $msg->setSenderRepliedNotifiedAt(new \DateTimeImmutable());
            $this->em->flush();
            return;
        }

        // Le sender consulte la réponse dans l'app mobile (auth requise).
        $messagesUrl = rtrim($this->publicUrl, '/').'/profile/messages';

        $email = (new TemplatedEmail())
            ->to($sender->getEmail())
            ->subject(sprintf(
                'Réponse de %s à votre message',
                $msg->getRepliedBy()?->getFullName() ?? 'l\'équipe TTM',
            ))
            ->htmlTemplate('email/user_message_reply.html.twig')
            ->textTemplate('email/user_message_reply.txt.twig')
            ->context([
                'sender' => $sender,
                'message' => $msg,
                'messagesUrl' => $messagesUrl,
            ]);

        try {
            $this->mailer->send($email);
            $msg->setSenderRepliedNotifiedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->logger->info('Notif réponse envoyée', ['messageId' => $msg->getId()]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Échec notif réponse', [
                'messageId' => $msg->getId(),
                'error' => $e->getMessage(),
            ]);
            // Pas de timestamp posé → retry messenger remettra ça plus tard.
            throw $e;
        }
    }
}
