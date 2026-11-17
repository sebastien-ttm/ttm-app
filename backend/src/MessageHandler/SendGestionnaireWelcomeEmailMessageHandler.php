<?php

namespace App\MessageHandler;

use App\Message\SendGestionnaireWelcomeEmailMessage;
use App\Repository\UserRepository;
use App\Service\PasswordResetService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * E-mail d'accès d'un gestionnaire externe, envoyé quand son compte est créé (ou quand le profil est ajouté à un compte
 * existant) : un lien pour créer son mot de passe (valable une semaine, à usage unique), puis un bouton vers
 * l'application et un bouton vers le backend. Le même mot de passe sert aux deux.
 *
 * Aucun envoi si le compte n'existe plus, est désactivé, n'a pas d'adresse ou n'est plus gestionnaire.
 */
#[AsMessageHandler]
class SendGestionnaireWelcomeEmailMessageHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetService $resets,
        private readonly MailerInterface $mailer,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(SendGestionnaireWelcomeEmailMessage $message): void
    {
        $user = $this->users->find($message->userId);
        if ($user === null || !$user->isActive() || !$user->isGestionnaire() || !$user->getEmail()) {
            return;
        }

        $base = rtrim($this->publicUrl, '/');
        $email = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Votre accès gestionnaire TTM : application et backend')
            ->htmlTemplate('email/gestionnaire_welcome.html.twig')
            ->textTemplate('email/gestionnaire_welcome.txt.twig')
            ->context([
                'user' => $user,
                'passwordUrl' => $this->resets->buildUrl($this->resets->issue($user, PasswordResetService::WELCOME_TTL_SECONDS)),
                'validityDays' => intdiv(PasswordResetService::WELCOME_TTL_SECONDS, 86400),
                'appUrl' => $base.'/',
                'backendUrl' => $base.'/admin',
                // Compte qui a déjà un mot de passe (adhérent à qui l'on ajoute le profil) : le texte le précise.
                'hasPassword' => $user->getPassword() !== null && $user->getPassword() !== '',
            ]);

        $this->mailer->send($email);
    }
}
