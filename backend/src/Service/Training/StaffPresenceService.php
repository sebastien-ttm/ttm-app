<?php

namespace App\Service\Training;

use App\Entity\StaffPresence;
use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Repository\StaffPresenceRepository;
use App\Repository\StaffPresenceTemplateRepository;
use App\Repository\StaffWeekUnavailabilityRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
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
        private readonly StaffPresenceTemplateRepository $presenceTemplates,
        private readonly StaffWeekUnavailabilityRepository $unavailabilities,
        private readonly TrainingSlotRepository $slots,
        private readonly TrainingSlotTemplateRepository $templates,
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

    /**
     * Positionne la présence d'un user sur une semaine précise d'après sa
     * semaine type personnelle : pour chaque créneau actif (non annulé)
     * de la semaine, force 'scheduled' si marqué présent dans le
     * template, 'unavailable' sinon — écrasant tout choix déjà posé sur
     * ce créneau (y compris une indisponibilité explicite) et retirant
     * le marqueur d'indisponibilité globale de la semaine.
     *
     * Partagé entre l'API mobile (l'user applique sa propre semaine
     * type) et le backend admin (un admin l'applique pour un membre du
     * staff qui ne s'est pas positionné lui-même).
     *
     * @return array{scheduledCount:int, unavailableCount:int}
     * @throws \DomainException si le user n'a aucun créneau configuré dans sa semaine type
     */
    public function applyTemplateToWeek(User $user, \DateTimeImmutable $weekStartsAt): array
    {
        $monday = WeeklyScheduleService::snapToMonday($weekStartsAt);

        $present = $this->presenceTemplates->findPresentTemplateIds($user);
        if ($present === []) {
            throw new \DomainException('Aucune semaine type configurée pour cet utilisateur.');
        }

        $existingUnav = $this->unavailabilities->findOneByUserAndWeek($user, $monday);
        if ($existingUnav !== null) {
            $this->em->remove($existingUnav);
        }

        $scheduledCount = 0;
        $unavailableCount = 0;
        foreach ($this->schedule->buildWeek($monday) as $slotRow) {
            if (!empty($slotRow['isCancelled'])) continue;
            $templateId = $slotRow['templateId'] ?? null;
            // Créneau occasionnel (sans template) : hors périmètre de la
            // semaine type, on n'y touche pas.
            if ($templateId === null) continue;

            $status = isset($present[$templateId]) ? StaffPresence::STATUS_SCHEDULED : StaffPresence::STATUS_UNAVAILABLE;
            $slotId = $slotRow['id'] ?? null;
            if ($slotId !== null) {
                $slot = $this->slots->find($slotId);
                if ($slot !== null) {
                    $this->setForSlot($user, $slot, $status);
                }
            } else {
                $template = $this->templates->find($templateId);
                if ($template !== null) {
                    $this->setForTemplate($user, $template, $monday, $status);
                }
            }
            if ($status === StaffPresence::STATUS_SCHEDULED) $scheduledCount++;
            else $unavailableCount++;
        }

        $this->em->flush();

        return ['scheduledCount' => $scheduledCount, 'unavailableCount' => $unavailableCount];
    }
}
