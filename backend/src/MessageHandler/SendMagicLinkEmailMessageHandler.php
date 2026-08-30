<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Enum\AdherentKind;
use App\Message\SendMagicLinkEmailMessage;
use App\Repository\UserRepository;
use App\Repository\WelcomeEmailTemplateRepository;
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
        private readonly WelcomeEmailTemplateRepository $welcomeTemplates,
    ) {
    }

    public function __invoke(SendMagicLinkEmailMessage $message): void
    {
        $user = $this->users->find($message->userId);
        if ($user === null) {
            return;
        }

        $webUrl = $this->magicLinks->buildWebUrl($message->clearToken, $message->next);
        $mobileUrl = $this->magicLinks->buildMobileUrl($message->clearToken, $message->next);

        // Cas 1 — email de bienvenue (import CSV FFTri) : sélection du
        // template selon le kind (new = premier import, renewal = adhérent
        // connu qui revient). Fallback sur le template `all` si aucun
        // template dédié n'existe.
        if ($message->isWelcome) {
            $kind = $message->isRenewal ? AdherentKind::Renewal : AdherentKind::New;
            $template = $this->welcomeTemplates->findForKind($kind);
            if ($template !== null) {
                $this->mailer->send($this->buildWelcomeEmail($user, $template->getSubject(), $template->getBodyHtml(), $webUrl));
                return;
            }
        }

        // Cas 2 — magic link classique (demande depuis login) ou fallback
        // si aucun modèle admin de bienvenue n'a été configuré.
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

    private function buildWelcomeEmail(User $user, string $subject, string $bodyTemplate, string $magicLink): TemplatedEmail
    {
        $body = strtr($bodyTemplate, [
            '{{ prenom }}' => htmlspecialchars($user->getPrenom(), ENT_QUOTES, 'UTF-8'),
            '{{ nom }}' => htmlspecialchars($user->getNom(), ENT_QUOTES, 'UTF-8'),
            '{{ magic_link }}' => htmlspecialchars($magicLink, ENT_QUOTES, 'UTF-8'),
        ]);
        $subjectResolved = strtr($subject, [
            '{{ prenom }}' => $user->getPrenom(),
            '{{ nom }}' => $user->getNom(),
        ]);

        return (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject($subjectResolved)
            ->htmlTemplate('email/welcome.html.twig')
            ->textTemplate('email/welcome.txt.twig')
            ->context([
                'user' => $user,
                'bodyHtml' => $body,
                'magicLink' => $magicLink,
            ]);
    }
}
