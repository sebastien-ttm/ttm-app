<?php

namespace App\MessageHandler;

use App\Message\NotifyEmailChangedMessage;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Une fois le changement confirmé : alerte l'ancienne adresse (au cas où
 * la demande n'émanerait pas du propriétaire) et souhaite la bienvenue
 * à la nouvelle. Chaque envoi est isolé — l'échec de l'un ne bloque pas
 * l'autre.
 */
#[AsMessageHandler]
class NotifyEmailChangedMessageHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyEmailChangedMessage $message): void
    {
        $user = $this->users->find($message->userId);
        if ($user === null) {
            return;
        }

        foreach ([[$message->oldEmail, false], [$message->newEmail, true]] as [$to, $isNewAddress]) {
            $email = (new TemplatedEmail())
                ->to($to)
                ->subject($isNewAddress
                    ? 'Votre adresse e-mail TTM a été mise à jour'
                    : 'Votre adresse e-mail TTM a été modifiée')
                ->htmlTemplate('email/email_changed.html.twig')
                ->textTemplate('email/email_changed.txt.twig')
                ->context([
                    'user' => $user,
                    'oldEmail' => $message->oldEmail,
                    'newEmail' => $message->newEmail,
                    'isNewAddress' => $isNewAddress,
                ]);
            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Échec notif changement d\'e-mail', [
                    'userId' => $user->getId(),
                    'to' => $to,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
