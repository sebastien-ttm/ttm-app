<?php

namespace App\Service;

use App\Controller\Admin\RaceProposalCrudController;
use App\Entity\RaceProposal;
use App\Entity\User;
use App\Enum\PushTopic;
use App\Enum\RaceProposalStatus;
use App\Enum\RaceType;
use App\Message\SendRaceModerationEmailsMessage;
use App\Repository\RaceProposalRepository;
use App\Repository\UserRepository;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Modération des compétitions proposées : soumission, validation et refus, avec les notifications qui vont avec. Utilisé par l'API (proposition d'un adhérent) et par le back-office (actions de
 * l'administrateur).
 *
 * Seuls les triathlons sont modérés (RaceType::requiresModeration) : les autres types de course sont publiés dès leur
 * proposition, comme celles d'un administrateur.
 *
 * Notifications : push ET e-mail aux administrateurs quand un triathlon attend leur validation, push ET e-mail à
 * l'auteur quand sa proposition est validée ou refusée (avec le motif du refus). Les e-mails partent par la file
 * Messenger (SendRaceModerationEmailsMessage). Une panne du push ou de la file ne doit jamais empêcher d'enregistrer la
 * décision : tout est protégé par un try/catch.
 */
class RaceProposalModerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RaceProposalRepository $proposals,
        private readonly UserRepository $users,
        private readonly WebPushNotifier $webPush,
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Une proposition doit-elle être validée par un administrateur avant publication ? Oui pour un triathlon, sauf s'il
     * vient d'un administrateur (personne d'autre à qui demander).
     */
    public function needsApproval(RaceProposal $proposal, bool $authorIsAdmin): bool
    {
        return !$authorIsAdmin && $proposal->getType()->requiresModeration();
    }

    /**
     * À appeler AVANT l'enregistrement d'une proposition qui vient d'être créée : « en attente de validation » pour un
     * triathlon d'adhérent, publiée d'emblée dans les autres cas (autre type de course, ou auteur administrateur).
     */
    public function submit(RaceProposal $proposal, bool $authorIsAdmin): void
    {
        $this->applyInitialStatus($proposal, $this->needsApproval($proposal, $authorIsAdmin));
    }

    /**
     * À appeler APRÈS l'enregistrement d'une proposition créée (ou dont le statut vient de changer, voir reassess()) :
     * prévient les administrateurs qu'elle attend une validation, ou — publiée d'emblée — annonce la compétition aux
     * adhérents.
     */
    public function afterSubmit(RaceProposal $proposal): void
    {
        if ($proposal->isPending()) {
            $this->notifyAdminsPending($proposal);
        } elseif ($proposal->isPublished()) {
            $this->announceToMembers($proposal);
        }
    }

    /**
     * À appeler quand l'auteur a modifié sa proposition (AVANT l'enregistrement), avec le type qu'elle avait avant la
     * modification. Retourne true si son statut a changé — à l'appelant d'enregistrer puis d'appeler afterSubmit() :
     *  - refusée puis corrigée : elle repart en validation (triathlon) ou est publiée (autre type, non modéré) ;
     *  - en attente mais devenue d'un autre type que triathlon : publiée directement ;
     *  - publiée, mais devenue un triathlon alors qu'elle n'en était pas un : elle repart en validation (sinon, changer
     *    le type après coup contournerait la modération).
     */
    public function reassess(RaceProposal $proposal, bool $authorIsAdmin, RaceType $previousType): bool
    {
        $needsApproval = $this->needsApproval($proposal, $authorIsAdmin);

        if ($proposal->isRefused() || ($proposal->isPending() && !$needsApproval)) {
            $this->applyInitialStatus($proposal, $needsApproval);

            return true;
        }
        if ($proposal->isPublished() && $needsApproval && !$previousType->requiresModeration()) {
            $proposal->setStatus(RaceProposalStatus::Pending)->setModerationNote(null);

            return true;
        }

        return false;
    }

    /** Publie la compétition (en attente ou refusée → validée). Annonce aux adhérents à la première publication. */
    public function validate(RaceProposal $proposal): bool
    {
        if (!$proposal->isPending() && !$proposal->isRefused()) {
            return false;
        }
        $firstTime = $proposal->getPublishedAt() === null;
        $proposal->setStatus(RaceProposalStatus::Validated)->setModerationNote(null);
        $proposal->markPublished($this->nextPublicationRank());
        $this->em->flush();

        if ($firstTime) {
            $this->announceToMembers($proposal);
        }
        $this->notifyAuthor(
            $proposal,
            'Compétition validée',
            sprintf('« %s » est publiée : les adhérents peuvent y répondre.', $proposal->getName()),
        );
        $this->emailAuthor($proposal, SendRaceModerationEmailsMessage::VALIDATED, null);

        return true;
    }

    /**
     * Refuse une proposition en attente. Le motif (facultatif) est montré à son auteur : dans l'appli, par notification
     * et par e-mail.
     */
    public function refuse(RaceProposal $proposal, ?string $note): bool
    {
        if (!$proposal->isPending()) {
            return false;
        }
        $proposal->setStatus(RaceProposalStatus::Refused)->setModerationNote($note);
        $this->em->flush();

        $reason = $proposal->getModerationNote();
        $this->notifyAuthor(
            $proposal,
            'Proposition non retenue',
            sprintf('« %s » n\'a pas été publiée%s', $proposal->getName(), $reason !== null ? ' : '.$reason : '.'),
        );
        $this->emailAuthor($proposal, SendRaceModerationEmailsMessage::REFUSED, $reason);

        return true;
    }

    /** Statut de départ : « en attente » si une validation est nécessaire, sinon publiée (avec son rang de publication). */
    private function applyInitialStatus(RaceProposal $proposal, bool $needsApproval): void
    {
        if ($needsApproval) {
            $proposal->setStatus(RaceProposalStatus::Pending)->setModerationNote(null);

            return;
        }
        $proposal->setStatus(RaceProposalStatus::Validated)->setModerationNote(null);
        $proposal->markPublished($this->nextPublicationRank());
    }

    /** Rang strictement supérieur à tous les identifiants et rangs de publication existants (voir RaceProposal). */
    private function nextPublicationRank(): int
    {
        return $this->proposals->highestPublicationMark() + 1;
    }

    private function announceToMembers(RaceProposal $proposal): void
    {
        $this->safely(fn () => $this->webPush->notifyTopic(
            PushTopic::Race,
            'Compétition proposée',
            sprintf('%s — %s', $proposal->getName(), $proposal->getRaceDate()->format('d/m/Y')),
            '/races/'.$proposal->getId(),
            'race-'.$proposal->getId(),
            exclude: $proposal->getAuthor(),
        ));
    }

    /** Un triathlon attend sa validation : push et e-mail à chaque administrateur (sauf l'auteur, s'il en est un). */
    private function notifyAdminsPending(RaceProposal $proposal): void
    {
        $this->safely(function () use ($proposal): void {
            $author = $proposal->getAuthor();
            $ids = [];
            foreach ($this->users->findActiveByRole(User::ROLE_ADMIN) as $admin) {
                // Un administrateur qui propose lui-même une compétition n'est pas prévenu de sa propre proposition.
                if ($admin->getId() !== $author->getId()) {
                    $ids[] = (int) $admin->getId();
                }
            }
            $this->webPush->notifyUsers(
                $ids,
                'Compétition à valider',
                sprintf('%s — %s, proposée par %s', $proposal->getName(), $proposal->getRaceDate()->format('d/m/Y'), $author->getFullName()),
                // La fiche dans le back-office (valider / refuser) ; à défaut de pouvoir la construire, l'accueil du back-office.
                $this->adminDetailPath($proposal),
                'race-pending-'.$proposal->getId(),
            );
        });
        $this->safely(fn () => $this->bus->dispatch(new SendRaceModerationEmailsMessage((int) $proposal->getId(), SendRaceModerationEmailsMessage::SUBMITTED)));
    }

    /** Résultat de la modération, pour l'auteur : indépendant de ses réglages de sujets (il est directement concerné). */
    private function notifyAuthor(RaceProposal $proposal, string $title, string $body): void
    {
        $this->safely(fn () => $this->webPush->notifyUsers(
            [(int) $proposal->getAuthor()->getId()],
            $title,
            $body,
            '/races/'.$proposal->getId(),
            'race-moderation-'.$proposal->getId(),
        ));
    }

    /** E-mail de la décision à l'auteur (validation ou refus, avec le motif figé à cet instant). */
    private function emailAuthor(RaceProposal $proposal, string $event, ?string $note): void
    {
        $this->safely(fn () => $this->bus->dispatch(new SendRaceModerationEmailsMessage((int) $proposal->getId(), $event, $note)));
    }

    private function adminDetailPath(RaceProposal $proposal): string
    {
        try {
            return $this->urlGenerator->generate('admin_dashboard', [
                'crudAction' => 'detail',
                'crudControllerFqcn' => RaceProposalCrudController::class,
                'entityId' => $proposal->getId(),
            ]);
        } catch (\Throwable) {
            return '/admin';
        }
    }

    private function safely(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            $this->logger->warning('Échec de la notification (modération des compétitions)', ['error' => $e->getMessage()]);
        }
    }
}
