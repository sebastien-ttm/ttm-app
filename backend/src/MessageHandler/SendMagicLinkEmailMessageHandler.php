<?php

namespace App\MessageHandler;

use App\Message\SendMagicLinkEmailMessage;
use App\Repository\UserRepository;
use App\Service\MagicLinkService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendMagicLinkEmailMessageHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MagicLinkService $magicLinks,
        private readonly MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendMagicLinkEmailMessage $message): void
    {
        $user = $this->users->find($message->userId);
        if ($user === null) {
            return;
        }

        $webUrl = $this->magicLinks->buildWebUrl($message->clearToken);
        $mobileUrl = $this->magicLinks->buildMobileUrl($message->clearToken);

        $subject = $message->isWelcome
            ? 'Bienvenue sur l\'application TTM'
            : 'Votre lien de connexion TTM';

        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject($subject)
            ->htmlTemplate('email/magic_link.html.twig')
            ->textTemplate('email/magic_link.txt.twig')
            ->context([
                'user' => $user,
                'webUrl' => $webUrl,
                'mobileUrl' => $mobileUrl,
                'isWelcome' => $message->isWelcome,
            ]);

        $this->mailer->send($email);
    }
}
