<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Entity\UserMessage;
use App\Message\NotifyNewUserMessageMessage;
use App\Repository\UserMessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class NotifyNewUserMessageMessageHandler
{
    public function __construct(
        private readonly UserMessageRepository $messages,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(NotifyNewUserMessageMessage $message): void
    {
        $msg = $this->messages->find($message->messageId);
        if ($msg === null) {
            return;
        }
        // Idempotence : la dispatch a déjà eu lieu.
        if ($msg->getRecipientsNotifiedAt() !== null) {
            return;
        }

        $recipients = $this->resolveRecipients($msg);

        // Claim du verrou AVANT l'envoi (cf. SendTrainingPlanEmailsMessageHandler) :
        // évite que le cron messenger ne rejoue toute la liste si le script
        // est tué pendant l'envoi (max_execution_time / --time-limit du
        // worker). Conséquence : si le worker meurt mid-loop, certains
        // destinataires ne reçoivent pas le mail — préférable à des doublons.
        $msg->setRecipientsNotifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($recipients === []) {
            return;
        }

        // Lien vers l'écran admin du message (le destinataire doit avoir
        // ROLE_ENTRAINEUR au moins, ce qui est vrai pour les admins et
        // entraîneurs ciblés).
        $adminUrl = rtrim($this->publicUrl, '/').'/admin';

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $recipient) {
            $email = (new TemplatedEmail())
                ->to($recipient->getEmail())
                ->subject(sprintf('Nouveau message de %s', $msg->getSender()->getFullName()))
                ->htmlTemplate('email/user_message_new.html.twig')
                ->textTemplate('email/user_message_new.txt.twig')
                ->context([
                    'recipient' => $recipient,
                    'message' => $msg,
                    'adminUrl' => $adminUrl,
                ]);
            try {
                $this->mailer->send($email);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $failed++;
                $this->logger->warning('Échec notif « nouveau message »', [
                    'messageId' => $msg->getId(),
                    'recipientId' => $recipient->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Notif nouveau message envoyée', [
            'messageId' => $msg->getId(),
            'sent' => $sent,
            'failed' => $failed,
            'recipients' => count($recipients),
        ]);
    }

    /**
     * Résout la liste des destinataires :
     *  - recipient null (« le club ») → tous les admins actifs (role='admin')
     *  - recipient non null → cet utilisateur uniquement, s'il a un email
     *
     * @return list<User>
     */
    private function resolveRecipients(UserMessage $msg): array
    {
        $recipient = $msg->getRecipient();
        if ($recipient !== null) {
            if (!$recipient->isActive() || $recipient->getEmail() === null) {
                return [];
            }
            return [$recipient];
        }
        return $this->users->findActiveByRole(User::ROLE_ADMIN);
    }
}
