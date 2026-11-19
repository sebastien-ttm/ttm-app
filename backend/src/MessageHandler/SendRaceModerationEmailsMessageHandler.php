<?php

namespace App\MessageHandler;

use App\Controller\Admin\RaceProposalCrudController;
use App\Entity\RaceProposal;
use App\Entity\User;
use App\Message\SendRaceModerationEmailsMessage;
use App\Repository\RaceProposalRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * E-mails de la modération d'une compétition proposée (voir RaceProposalModerator, qui envoie aussi les notifications
 * push) :
 *  - SUBMITTED : à chaque administrateur actif (sauf l'auteur), avec un lien vers la fiche dans le back-office ;
 *  - VALIDATED : à l'auteur, la compétition est publiée ;
 *  - REFUSED   : à l'auteur, avec le motif saisi par l'administrateur.
 *
 * Un message dont la situation a changé avant le passage du worker (proposition déjà traitée, corrigée par son auteur,
 * supprimée) est ignoré. Un échec d'envoi à l'un n'empêche pas les autres et n'est pas rejoué (pas de doublon).
 */
#[AsMessageHandler]
class SendRaceModerationEmailsMessageHandler
{
    public function __construct(
        private readonly RaceProposalRepository $proposals,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(SendRaceModerationEmailsMessage $message): void
    {
        $proposal = $this->proposals->find($message->proposalId);
        if ($proposal === null) {
            return;
        }

        match ($message->event) {
            SendRaceModerationEmailsMessage::SUBMITTED => $proposal->isPending() ? $this->toAdmins($proposal) : null,
            SendRaceModerationEmailsMessage::VALIDATED => $proposal->isPublished() ? $this->toAuthor($proposal, $message) : null,
            SendRaceModerationEmailsMessage::REFUSED => $proposal->isRefused() ? $this->toAuthor($proposal, $message) : null,
            default => null,
        };
    }

    private function toAdmins(RaceProposal $proposal): void
    {
        $author = $proposal->getAuthor();
        $subject = sprintf('Compétition à valider : %s', $proposal->getName());
        foreach ($this->users->findActiveByRole(User::ROLE_ADMIN) as $admin) {
            // Un administrateur n'est pas prévenu de sa propre proposition (elle est d'ailleurs publiée d'emblée).
            if ($admin->getId() === $author->getId()) {
                continue;
            }
            $this->send($admin, $subject, $proposal, SendRaceModerationEmailsMessage::SUBMITTED, null);
        }
    }

    private function toAuthor(RaceProposal $proposal, SendRaceModerationEmailsMessage $message): void
    {
        $author = $proposal->getAuthor();
        if (!$author->isActive()) {
            return;
        }
        $subject = $message->event === SendRaceModerationEmailsMessage::VALIDATED
            ? sprintf('Votre compétition « %s » est validée', $proposal->getName())
            : sprintf('Votre compétition « %s » n\'a pas été retenue', $proposal->getName());
        $this->send($author, $subject, $proposal, $message->event, $message->note);
    }

    private function send(User $recipient, string $subject, RaceProposal $proposal, string $event, ?string $note): void
    {
        if (trim($recipient->getEmail()) === '') {
            return;
        }
        $base = rtrim($this->publicUrl, '/');
        $email = (new TemplatedEmail())
            ->to($recipient->getEmail())
            ->subject($subject)
            ->htmlTemplate('email/race_moderation.html.twig')
            ->textTemplate('email/race_moderation.txt.twig')
            ->context([
                'recipient' => $recipient,
                'proposal' => $proposal,
                'event' => $event,
                'note' => $note,
                // La fiche dans l'appli (connexion requise) et, pour les administrateurs, dans le back-office.
                'appUrl' => $base.'/races/'.$proposal->getId(),
                'adminUrl' => $base.'/admin?'.http_build_query([
                    'crudAction' => 'detail',
                    'crudControllerFqcn' => RaceProposalCrudController::class,
                    'entityId' => $proposal->getId(),
                ]),
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Échec de l\'e-mail de modération d\'une compétition proposée', [
                'proposalId' => $proposal->getId(),
                'event' => $event,
                'recipientId' => $recipient->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
