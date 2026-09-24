<?php

namespace App\MessageHandler;

use App\Message\SendEmailChangeConfirmationMessage;
use App\Repository\EmailChangeRequestRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendEmailChangeConfirmationMessageHandler
{
    public function __construct(
        private readonly EmailChangeRequestRepository $requests,
        private readonly MailerInterface $mailer,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(SendEmailChangeConfirmationMessage $message): void
    {
        $request = $this->requests->find($message->requestId);
        // Demande annulée (remplacée par une plus récente), déjà confirmée
        // ou expirée entre-temps : rien à envoyer.
        if ($request === null || !$request->isUsable()) {
            return;
        }

        $user = $request->getUser();
        $confirmUrl = rtrim($this->publicUrl, '/').'/confirm-email-change?token='.urlencode($message->clearToken);

        // Envoyé à l'adresse ACTUELLE (getEmail() n'a pas encore changé) :
        // c'est la preuve que la demande émane bien du propriétaire de la boîte.
        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Confirmez le changement de votre adresse e-mail TTM')
            ->htmlTemplate('email/email_change_confirm.html.twig')
            ->textTemplate('email/email_change_confirm.txt.twig')
            ->context([
                'user' => $user,
                'newEmail' => $request->getNewEmail(),
                'confirmUrl' => $confirmUrl,
                'validHours' => max(1, (int) ceil(($request->getExpiresAt()->getTimestamp() - time()) / 3600)),
            ]);

        $this->mailer->send($email);
    }
}
