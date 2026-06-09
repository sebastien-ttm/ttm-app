<?php

namespace App\Controller\Api;

use App\Entity\StaffPresence;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\StaffPresenceRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Service\Training\StaffPresenceService;
use App\Service\Training\WeeklyScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API mobile pour la présence du staff (encadrants, et éventuellement
 * entraîneurs depuis l'app). L'accès est limité aux users qui ont au
 * moins l'un des deux profils.
 */
#[IsGranted('ROLE_USER')]
class StaffPresenceController extends AbstractController
{
    public function __construct(
        private readonly StaffPresenceRepository $presences,
        private readonly TrainingSlotRepository $slots,
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly StaffPresenceService $service,
        private readonly WeeklyScheduleService $schedule,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Garde-fou : route réservée aux profils Encadrant ou Entraîneur. */
    private function ensureStaff(User $user): void
    {
        if (!$user->isEncadrant() && !$user->isEntraineur()) {
            throw $this->createAccessDeniedException('Réservé au staff.');
        }
    }

    /**
     * Vue d'une semaine pour le staff connecté :
     *  - tous les créneaux d'entraînement de la semaine (issus de la
     *    semaine type + overrides + occasionnels)
     *  - leur état de présence pour le user courant
     *  - les tâches custom (créneaux hors entraînement)
     */
    #[Route('/api/me/staff-presence', name: 'api_staff_presence_week', methods: ['GET'])]
    public function week(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $weekParam = (string) $request->query->get('week', '');
        try {
            $weekDate = $weekParam !== '' ? new \DateTimeImmutable($weekParam) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Paramètre week invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $monday = WeeklyScheduleService::snapToMonday($weekDate);

        // 1) Tous les créneaux d'entraînement de la semaine (vue admin sans
        //    filtre audience : le staff voit tout).
        $slotRows = $this->schedule->buildWeek($monday);

        // 2) Présences du user pour cette semaine, indexées par slot.id
        //    (slot.id null = tâche custom).
        $myPresences = $this->presences->findByUserAndWeek($user, $monday);
        $presencesBySlot = [];
        $customTasks = [];
        foreach ($myPresences as $p) {
            if ($p->getSlot() !== null) {
                $presencesBySlot[$p->getSlot()->getId()] = $p;
            } else {
                $customTasks[] = $this->serializePresence($p);
            }
        }

        // Enrichit chaque slot avec myPresence (null si pas réservé)
        $slotsWithPresence = array_map(function (array $slot) use ($presencesBySlot) {
            $sid = $slot['id'];
            $p = $sid !== null ? ($presencesBySlot[$sid] ?? null) : null;
            $slot['myPresence'] = $p !== null ? [
                'id' => $p->getId(),
                'status' => $p->getStatus(),
                'notes' => $p->getNotes(),
            ] : null;
            return $slot;
        }, $slotRows);

        return new JsonResponse([
            'week' => $monday->format('Y-m-d'),
            'slots' => $slotsWithPresence,
            'customTasks' => $customTasks,
        ]);
    }

    /**
     * Pose / met à jour la présence du user sur un créneau (slot existant
     * matérialisé OU template virtuel).
     *
     * Body :
     *  - status : "scheduled" | "attended"
     *  - slotId | templateId : l'un des deux est requis pour les présences
     *    liées à un créneau (le templateId déclenche la matérialisation).
     *  - week : YYYY-MM-DD (requis si templateId)
     *  - notes : optionnel
     */
    #[Route('/api/me/staff-presence/slot', name: 'api_staff_presence_set_slot', methods: ['POST'])]
    public function setSlot(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // L'app mobile ne permet plus que la pose / annulation d'une présence
        // (= « Je serai là »). La validation effective (status='attended')
        // est désormais une prérogative backend uniquement. On force donc
        // le statut côté serveur — un payload qui tente 'attended' est
        // ignoré silencieusement (downgrade vers 'scheduled').
        $status = StaffPresence::STATUS_SCHEDULED;
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;

        $slotId = isset($payload['slotId']) && $payload['slotId'] !== '' ? (int) $payload['slotId'] : null;
        $templateId = isset($payload['templateId']) && $payload['templateId'] !== '' ? (int) $payload['templateId'] : null;

        if ($slotId !== null) {
            $slot = $this->slots->find($slotId);
            if ($slot === null) {
                throw $this->createNotFoundException();
            }
            $presence = $this->service->setForSlot($user, $slot, $status, $notes);
        } elseif ($templateId !== null) {
            $weekRaw = (string) ($payload['week'] ?? '');
            try {
                $week = $weekRaw !== '' ? new \DateTimeImmutable($weekRaw) : new \DateTimeImmutable('today');
            } catch (\Exception) {
                return new JsonResponse(['error' => 'week invalide'], Response::HTTP_BAD_REQUEST);
            }
            $template = $this->templates->find($templateId);
            if ($template === null) {
                throw $this->createNotFoundException();
            }
            $presence = $this->service->setForTemplate($user, $template, $week, $status, $notes);
        } else {
            return new JsonResponse(['error' => 'slotId ou templateId requis.'], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();
        return new JsonResponse($this->serializePresence($presence), Response::HTTP_OK);
    }

    /** Crée une tâche custom (créneau hors entraînement). */
    #[Route('/api/me/staff-presence/custom', name: 'api_staff_presence_create_custom', methods: ['POST'])]
    public function createCustom(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $dateRaw = (string) ($payload['date'] ?? '');
        $timeRaw = (string) ($payload['startTime'] ?? '');
        $duration = (int) ($payload['durationMinutes'] ?? 60);
        $status = (string) ($payload['status'] ?? StaffPresence::STATUS_SCHEDULED);

        if ($title === '') {
            return new JsonResponse(['error' => 'Titre requis.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $date = new \DateTimeImmutable($dateRaw);
            $time = new \DateTimeImmutable($timeRaw);
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Date ou heure invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $presence = new StaffPresence($user);
        $presence->setTitle($title);
        $presence->setDate($date);
        $presence->setStartTime($time);
        $presence->setDurationMinutes(max(5, min(600, $duration)));
        $presence->setStatus(in_array($status, StaffPresence::STATUSES, true) ? $status : StaffPresence::STATUS_SCHEDULED);
        $presence->setNotes(isset($payload['notes']) ? (string) $payload['notes'] : null);

        $this->em->persist($presence);
        $this->em->flush();

        return new JsonResponse($this->serializePresence($presence), Response::HTTP_CREATED);
    }

    /** Met à jour le statut/notes d'une présence existante. */
    #[Route('/api/me/staff-presence/{id}', name: 'api_staff_presence_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $presence = $this->presences->find($id);
        if ($presence === null || $presence->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($payload['status']) && in_array($payload['status'], StaffPresence::STATUSES, true)) {
            $presence->setStatus((string) $payload['status']);
        }
        if (array_key_exists('notes', $payload)) {
            $presence->setNotes($payload['notes'] !== null ? (string) $payload['notes'] : null);
        }
        // Pour les tâches custom uniquement : autoriser modification des champs
        if ($presence->isCustom()) {
            if (isset($payload['title'])) {
                $presence->setTitle(trim((string) $payload['title']));
            }
            if (isset($payload['date'])) {
                try {
                    $presence->setDate(new \DateTimeImmutable((string) $payload['date']));
                } catch (\Exception) { /* ignore */ }
            }
            if (isset($payload['startTime'])) {
                try {
                    $presence->setStartTime(new \DateTimeImmutable((string) $payload['startTime']));
                } catch (\Exception) { /* ignore */ }
            }
            if (isset($payload['durationMinutes'])) {
                $presence->setDurationMinutes(max(5, min(600, (int) $payload['durationMinutes'])));
            }
        }

        $this->em->flush();
        return new JsonResponse($this->serializePresence($presence));
    }

    #[Route('/api/me/staff-presence/{id}', name: 'api_staff_presence_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $presence = $this->presences->find($id);
        if ($presence === null || $presence->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }
        $this->em->remove($presence);
        $this->em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePresence(StaffPresence $p): array
    {
        return [
            'id' => $p->getId(),
            'slotId' => $p->getSlot()?->getId(),
            'isCustom' => $p->isCustom(),
            'title' => $p->getTitle(),
            'date' => $p->getDate()->format('Y-m-d'),
            'startTime' => $p->getStartTime()->format('H:i'),
            'durationMinutes' => $p->getDurationMinutes(),
            'weekStartsAt' => $p->getWeekStartsAt()->format('Y-m-d'),
            'status' => $p->getStatus(),
            'notes' => $p->getNotes(),
        ];
    }
}
