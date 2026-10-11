<?php

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Entity\MemberGroupMember;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Repository\EventAttendanceRepository;
use App\Service\MemberGroup\MemberGroupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistrement du vote de présence d'un adhérent à un événement soumis au vote. Partagé par
 * l'API de l'appli (l'adhérent vote lui-même) et le backend (l'administrateur surcharge le vote
 * d'un adhérent) pour que les deux fassent exactement la même chose.
 */
class EventAttendanceService
{
    public function __construct(
        private readonly EventAttendanceRepository $attendances,
        private readonly MemberGroupService $memberGroups,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Enregistre, change ou retire (`$status` null) le vote de l'adhérent, puis synchronise le groupe
     * d'adhérents lié à l'événement :
     *  - « présent » → l'adhérent est ajouté au groupe (créé au besoin) ;
     *  - « peut-être », « absent » ou vote retiré → il en est retiré.
     */
    public function setVote(Event $event, User $user, ?AttendanceStatus $status): void
    {
        $existing = $this->attendances->findOneByUserAndEvent($user, $event);
        if ($status === null) {
            // Retire le vote (l'adhérent hésite à nouveau) — supprime la ligne.
            if ($existing !== null) {
                $this->em->remove($existing);
            }
        } elseif ($existing !== null) {
            $existing->setStatus($status);
        } else {
            $this->em->persist(new EventAttendance($user, $event, $status));
        }

        // Le groupe est créé à la volée (source=event) : pas besoin que l'admin le pré-crée.
        $group = $this->memberGroups->ensureGroupForEvent($event);
        if ($status === AttendanceStatus::Yes) {
            $this->memberGroups->addMember($group, $user, MemberGroupMember::SOURCE_EVENT_VOTE);
        } else {
            $this->memberGroups->removeMember($group, $user);
        }

        $this->em->flush();
    }
}
