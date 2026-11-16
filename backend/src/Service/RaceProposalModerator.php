<?php

namespace App\Service;

use App\Entity\RaceProposal;
use App\Entity\User;
use App\Enum\PushTopic;
use App\Enum\RaceProposalStatus;
use App\Repository\RaceProposalRepository;
use App\Repository\UserRepository;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Modération des compétitions proposées : soumission, validation, refus, mise en pause et reprise, avec les
 * notifications push qui vont avec. Utilisé par l'API (proposition d'un adhérent) et par le back-office (actions de
 * l'administrateur).
 *
 * Pas d'e-mail : les alertes à l'administrateur passent par le push et la pastille du menu du back-office.
 * Une panne du push ne doit jamais empêcher d'enregistrer la décision : tout est protégé par un try/catch.
 */
class RaceProposalModerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RaceProposalRepository $proposals,
        private readonly UserRepository $users,
        private readonly WebPushNotifier $webPush,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * À appeler AVANT l'enregistrement d'une proposition qui vient d'être créée : « en attente de validation », sauf
     * pour un administrateur (personne d'autre à qui demander) dont la proposition est publiée d'emblée.
     */
    public function submit(RaceProposal $proposal, bool $authorIsAdmin): void
    {
        if ($authorIsAdmin) {
            $proposal->setStatus(RaceProposalStatus::Validated)->setModerationNote(null);
            $proposal->markPublished($this->nextPublicationRank());
        } else {
            $proposal->setStatus(RaceProposalStatus::Pending)->setModerationNote(null);
        }
    }

    /**
     * À appeler APRÈS l'enregistrement d'une proposition créée (ou corrigée après un refus) : prévient les
     * administrateurs qu'elle attend une validation, ou — publiée d'emblée — annonce la compétition aux adhérents.
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
     * L'auteur d'une proposition refusée la corrige : elle repart en validation. Retourne true si c'est le cas (à
     * l'appelant d'enregistrer puis d'appeler afterSubmit()).
     */
    public function resubmit(RaceProposal $proposal): bool
    {
        if (!$proposal->isRefused()) {
            return false;
        }
        $proposal->setStatus(RaceProposalStatus::Pending)->setModerationNote(null);

        return true;
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

        return true;
    }

    /** Refuse une proposition en attente ; le motif (facultatif) est montré à son auteur. */
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

        return true;
    }

    /** Met en pause une compétition publiée : toujours visible, mais plus de nouvelle réponse. Motif facultatif. */
    public function pause(RaceProposal $proposal, ?string $note): bool
    {
        if ($proposal->getStatus() !== RaceProposalStatus::Validated) {
            return false;
        }
        $proposal->setStatus(RaceProposalStatus::Paused)->setModerationNote($note);
        $this->em->flush();

        $reason = $proposal->getModerationNote();
        $this->notifyAuthor(
            $proposal,
            'Compétition mise en pause',
            sprintf('« %s » est en pause%s', $proposal->getName(), $reason !== null ? ' : '.$reason : '.'),
        );

        return true;
    }

    /** Reprend une compétition en pause (les réponses sont de nouveau ouvertes). */
    public function resume(RaceProposal $proposal): bool
    {
        if (!$proposal->isPaused()) {
            return false;
        }
        $proposal->setStatus(RaceProposalStatus::Validated)->setModerationNote(null);
        $this->em->flush();

        $this->notifyAuthor(
            $proposal,
            'Compétition reprise',
            sprintf('« %s » n\'est plus en pause : les réponses sont de nouveau ouvertes.', $proposal->getName()),
        );

        return true;
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
                '/admin',
                'race-pending-'.$proposal->getId(),
            );
        });
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

    private function safely(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            $this->logger->warning('Échec de la notification push (modération des compétitions)', ['error' => $e->getMessage()]);
        }
    }
}
