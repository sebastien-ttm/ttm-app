<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\NotifyExternalMemberCreatedMessage;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envoie un e-mail à chaque administrateur actif quand un adhérent externe s'inscrit.
 * Un échec d'envoi à l'un n'empêche pas les autres (et n'est pas rejoué : pas de doublon).
 */
#[AsMessageHandler]
class NotifyExternalMemberCreatedMessageHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(NotifyExternalMemberCreatedMessage $message): void
    {
        $member = $this->users->find($message->userId);
        if ($member === null) {
            return;
        }
        $admins = $this->users->findActiveByRole(User::ROLE_ADMIN);
        if ($admins === []) {
            return;
        }

        $season = $this->seasons->findCurrent();
        // Page d'activation, par le dashboard EasyAdmin (qui pose le contexte de la page).
        $adminUrl = rtrim($this->publicUrl, '/').'/admin?routeName=admin_external_members';

        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->to($admin->getEmail())
                ->subject(sprintf('Nouvel adhérent externe : %s', $member->getFullName()))
                ->htmlTemplate('email/external_member_created.html.twig')
                ->textTemplate('email/external_member_created.txt.twig')
                ->context([
                    'admin' => $admin,
                    'member' => $member,
                    'seasonLabel' => $season !== null ? (string) $season : null,
                    'adminUrl' => $adminUrl,
                ]);

            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('Échec de la notification « nouvel adhérent externe »', [
                    'memberId' => $member->getId(),
                    'adminId' => $admin->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
