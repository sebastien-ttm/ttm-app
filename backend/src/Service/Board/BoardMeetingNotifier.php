<?php

namespace App\Service\Board;

use App\Entity\BoardMeeting;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\WebPush\WebPushNotifier;

/**
 * Notification push aux membres du comité directeur quand une réunion est programmée, pour qu'ils ajoutent leurs
 * points à l'ordre du jour.
 */
class BoardMeetingNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WebPushNotifier $webPush,
    ) {
    }

    public function notifyScheduled(BoardMeeting $meeting, ?User $except = null): void
    {
        $ids = [];
        foreach ($this->users->findCommitteeMembers() as $member) {
            if ($member->isActive() && ($except === null || $member->getId() !== $except->getId())) {
                $ids[] = (int) $member->getId();
            }
        }
        $fmt = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, 'Europe/Paris', null, "EEEE d MMMM 'à' HH'h'mm");
        $this->webPush->notifyUsers(
            $ids,
            'Comité directeur : nouvelle réunion',
            sprintf('Réunion le %s%s. Ajoutez vos points à l\'ordre du jour.', (string) $fmt->format($meeting->getScheduledAt()), $meeting->getLocation() ? ' ('.$meeting->getLocation().')' : ''),
            '/board/'.$meeting->getId(),
            'board-meeting-'.$meeting->getId(),
        );
    }
}
