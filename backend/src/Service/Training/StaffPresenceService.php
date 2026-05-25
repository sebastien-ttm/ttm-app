<?php

namespace App\Service\Training;

use App\Entity\StaffPresence;
use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Repository\StaffPresenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralise les opérations de présence staff (encadrants / entraineurs).
 *
 * En particulier, gère la matérialisation d'un TrainingSlot virtuel
 * (issu de la semaine type) quand une présence y est créée pour la
 * première fois : pas d'override (les champs restent identiques au
 * template) mais le slot a un id en BDD, ce qui permet de poser la
 * FK depuis StaffPresence.
 */
class StaffPresenceService
{
    public function __construct(
        private readonly StaffPresenceRepository $presences,
        private readonly WeeklyScheduleService $schedule,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Récupère ou crée une présence d'un user sur un slot existant.
     */
    public function setForSlot(User $user, TrainingSlot $slot, string $status, ?string $notes = null): StaffPresence
    {
        $presence = $this->presences->findOneByUserAndSlot($user, $slot);
        if ($presence === null) {
            $presence = (new StaffPresence($user))->fillFromSlot($slot);
            $this->em->persist($presence);
        }
        $presence->setStatus($status);
        if ($notes !== null) {
            $presence->setNotes($notes);
        }
        return $presence;
    }

    /**
     * Comme setForSlot mais à partir d'un template virtuel pour une
     * semaine donnée : on matérialise le slot d'abord (sans rien modifier)
     * pour avoir un id, puis on pose la présence.
     */
    public function setForTemplate(
        User $user,
        TrainingSlotTemplate $template,
        \DateTimeImmutable $weekStartsAt,
        string $status,
        ?string $notes = null,
    ): StaffPresence {
        $slot = $this->schedule->materializeOverride($weekStartsAt, $template);
        // Note : materializeOverride persiste mais ne flush pas. Si c'est
        // un nouveau slot virtuel, il n'a pas encore d'ID — flush ici.
        if ($slot->getId() === null) {
            $this->em->flush();
        }
        return $this->setForSlot($user, $slot, $status, $notes);
    }

    /**
     * Supprime la présence d'un user sur un slot (annule la réservation).
     */
    public function unset(User $user, TrainingSlot $slot): void
    {
        $presence = $this->presences->findOneByUserAndSlot($user, $slot);
        if ($presence !== null) {
            $this->em->remove($presence);
        }
    }
}
