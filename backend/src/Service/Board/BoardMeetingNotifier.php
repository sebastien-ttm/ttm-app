<?php

namespace App\Service\Board;

use App\Entity\BoardAgendaItem;
use App\Entity\BoardMeeting;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\WebPush\WebPushNotifier;
use Psr\Log\LoggerInterface;

/**
 * Notifications push aux membres du comité directeur et aux entraîneurs (invités aux réunions) :
 *  - quand une réunion est programmée, pour qu'ils ajoutent leurs points à l'ordre du jour ;
 *  - quand elle est close, pour les prévenir que le compte rendu est consultable dans l'appli.
 */
class BoardMeetingNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WebPushNotifier $webPush,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyScheduled(BoardMeeting $meeting, ?User $except = null): void
    {
        $fmt = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, 'Europe/Paris', null, "EEEE d MMMM 'à' HH'h'mm");
        $this->webPush->notifyUsers(
            $this->recipientIds($except),
            'Comité directeur : nouvelle réunion',
            sprintf('Réunion le %s%s. Ajoutez vos points à l\'ordre du jour.', (string) $fmt->format($meeting->getScheduledAt()), $meeting->getLocation() ? ' ('.$meeting->getLocation().')' : ''),
            '/board/'.$meeting->getId(),
            'board-meeting-'.$meeting->getId(),
        );
    }

    /**
     * La réunion vient d'être close : le compte rendu est consultable (la notification ouvre la réunion dans l'appli).
     * Sans effet bloquant : si l'envoi échoue (file de messages indisponible…), la clôture reste valide — retourne
     * false dans ce cas, et le journal en garde la trace.
     */
    public function notifyClosed(BoardMeeting $meeting, ?User $except = null): bool
    {
        $actionCount = array_sum(array_map(static fn (BoardAgendaItem $i) => $i->getActions()->count(), $meeting->getItems()->toArray()));
        $body = sprintf(
            'Compte rendu du %s : %d/%d points traités%s.',
            $meeting->getLabel(),
            $meeting->getAnsweredCount(),
            $meeting->getItemCount(),
            $actionCount > 0 ? sprintf(', %d action%s décidée%s', $actionCount, $actionCount > 1 ? 's' : '', $actionCount > 1 ? 's' : '') : '',
        );
        try {
            $this->webPush->notifyUsers(
                $this->recipientIds($except),
                'Comité directeur : compte rendu disponible',
                $body,
                '/board/'.$meeting->getId(),
                'board-meeting-closed-'.$meeting->getId(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Notification de clôture de réunion CoDir non envoyée.', ['meeting' => $meeting->getId(), 'exception' => $e]);

            return false;
        }

        return true;
    }

    /**
     * Membres du CoDir + entraîneurs (invités aux réunions : ils lisent l'ordre du jour et le compte rendu), actifs, hors
     * `$except` (celui qui vient d'agir). Un compte qui est les deux n'est retenu qu'une fois.
     *
     * @return list<int>
     */
    private function recipientIds(?User $except): array
    {
        $ids = [];
        foreach ([...$this->users->findCommitteeMembers(), ...$this->users->findCoaches()] as $member) {
            if ($member->isActive() && ($except === null || $member->getId() !== $except->getId())) {
                $ids[(int) $member->getId()] = (int) $member->getId();
            }
        }

        return array_values($ids);
    }
}
