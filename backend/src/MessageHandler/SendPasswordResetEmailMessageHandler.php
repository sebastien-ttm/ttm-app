<?php

namespace App\MessageHandler;

use App\Message\SendPasswordResetEmailMessage;
use App\Repository\UserRepository;
use App\Service\PasswordResetService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendPasswordResetEmailMessageHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetService $resets,
        private readonly MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendPasswordResetEmailMessage $message): void
    {
        $user = $this->users->find($message->userId);
        if ($user === null || !$user->isActive()) {
            return;
        }

        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe TTM')
            ->htmlTemplate('email/password_reset.html.twig')
            ->textTemplate('email/password_reset.txt.twig')
            ->context([
                'user' => $user,
                'resetUrl' => $this->resets->buildUrl($message->clearToken),
                'validityMinutes' => intdiv(PasswordResetService::TTL_SECONDS, 60),
            ]);

        $this->mailer->send($email);
    }
}
