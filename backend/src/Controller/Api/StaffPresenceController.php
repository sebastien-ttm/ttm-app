<?php

namespace App\Controller\Api;

use App\Entity\StaffPresence;
use App\Entity\StaffPresenceTemplate;
use App\Entity\User;
use App\Enum\Profile;
use App\Entity\StaffWeekUnavailability;
use App\Repository\StaffPresenceRepository;
use App\Repository\StaffPresenceTemplateRepository;
use App\Repository\StaffWeekUnavailabilityRepository;
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
        private readonly StaffWeekUnavailabilityRepository $unavailabilities,
        private readonly StaffPresenceTemplateRepository $presenceTemplates,
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

        // 3) TOUTES les présences staff de la semaine, réindexées par slot.id
        //    → permet d'afficher qui est déjà positionné sur chaque créneau.
        //    On EXCLUT les présences de statut 'unavailable' : « je ne serai
        //    pas là » n'est pas une inscription positive et ne doit pas faire
        //    apparaître la personne dans la liste des présents.
        $allPresences = $this->presences->findStaffPresencesForWeekGroupedByUser($monday);
        $assignedBySlot = [];
        foreach ($allPresences as $userPresences) {
            foreach ($userPresences as $p) {
                $slot = $p->getSlot();
                if ($slot === null) continue;
                if ($p->getStatus() === StaffPresence::STATUS_UNAVAILABLE) continue;
                $assignedBySlot[$slot->getId()] ??= [];
                $assignedBySlot[$slot->getId()][] = [
                    'userId' => $p->getUser()->getId(),
                    'fullName' => $p->getUser()->getFullName(),
                    'role' => $p->getUser()->isEntraineur() ? 'entraineur' : 'encadrant',
                    'status' => $p->getStatus(),
                    'notes' => $p->getNotes(),
                ];
            }
        }

        // Enrichit chaque slot avec myPresence (null si pas réservé) + la
        // liste complète des staff positionnés (vide si personne).
        $slotsWithPresence = array_map(function (array $slot) use ($presencesBySlot, $assignedBySlot) {
            $sid = $slot['id'];
            $p = $sid !== null ? ($presencesBySlot[$sid] ?? null) : null;
            $slot['myPresence'] = $p !== null ? [
                'id' => $p->getId(),
                'status' => $p->getStatus(),
                'notes' => $p->getNotes(),
            ] : null;
            $slot['assignedStaff'] = $sid !== null ? ($assignedBySlot[$sid] ?? []) : [];
            return $slot;
        }, $slotRows);

        // 3) Statut d'indisponibilité déclarée pour cette semaine
        $unav = $this->unavailabilities->findOneByUserAndWeek($user, $monday);

        return new JsonResponse([
            'week' => $monday->format('Y-m-d'),
            'slots' => $slotsWithPresence,
            'customTasks' => $customTasks,
            'unavailable' => $unav !== null,
            'unavailableNotes' => $unav?->getNotes(),
        ]);
    }

    /**
     * Marque le user comme non-disponible sur cette semaine.
     * Body : { week: "YYYY-MM-DD", notes?: string }
     * Idempotent (met à jour la note si déjà posé).
     */
    #[Route('/api/me/staff-presence/unavailable', name: 'api_staff_presence_set_unavailable', methods: ['POST'])]
    public function setUnavailable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $weekRaw = (string) ($payload['week'] ?? '');
        try {
            $week = $weekRaw !== '' ? new \DateTimeImmutable($weekRaw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(['error' => 'week invalide'], Response::HTTP_BAD_REQUEST);
        }
        $monday = WeeklyScheduleService::snapToMonday($week);
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;

        $existing = $this->unavailabilities->findOneByUserAndWeek($user, $monday);
        if ($existing !== null) {
            $existing->setNotes($notes);
        } else {
            $this->em->persist(new StaffWeekUnavailability($user, $monday, $notes));
        }

        // Répercute l'indisponibilité par créneau : pose une StaffPresence
        // 'unavailable' sur chaque slot de la semaine. L'user pourra ensuite
        // modifier unitairement (bouton « Je serai là » sur un slot pour
        // basculer scheduled → écrase la valeur unavailable).
        $slotRows = $this->schedule->buildWeek($monday);
        foreach ($slotRows as $slotRow) {
            if (!empty($slotRow['isCancelled'])) continue;
            $slotId = $slotRow['id'] ?? null;
            $templateId = $slotRow['templateId'] ?? null;
            if ($slotId !== null) {
                $slot = $this->slots->find($slotId);
                if ($slot !== null) {
                    $this->service->setForSlot($user, $slot, StaffPresence::STATUS_UNAVAILABLE, null);
                }
            } elseif ($templateId !== null) {
                $template = $this->templates->find($templateId);
                if ($template !== null) {
                    $this->service->setForTemplate($user, $template, $monday, StaffPresence::STATUS_UNAVAILABLE, null);
                }
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'week' => $monday->format('Y-m-d'),
            'unavailable' => true,
            'unavailableNotes' => $notes,
        ]);
    }

    /**
     * Pose « unavailable » sur les créneaux de la semaine où l'user n'a
     * PAS ENCORE de présence (scheduled/attended/unavailable). Ne touche
     * ni les slots déjà positionnés, ni le marqueur hebdo — geste ciblé
     * « je remplis les cases restantes ».
     *
     * Body : { week: "YYYY-MM-DD" }
     */
    #[Route('/api/me/staff-presence/unavailable-missing', name: 'api_staff_presence_set_unavailable_missing', methods: ['POST'])]
    public function setUnavailableMissing(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $weekRaw = (string) ($payload['week'] ?? '');
        try {
            $week = $weekRaw !== '' ? new \DateTimeImmutable($weekRaw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(['error' => 'week invalide'], Response::HTTP_BAD_REQUEST);
        }
        $monday = WeeklyScheduleService::snapToMonday($week);

        // Index des slots déjà positionnés par l'user (par slot id).
        $existingByslotId = [];
        foreach ($this->presences->findByUserAndWeek($user, $monday) as $p) {
            $slot = $p->getSlot();
            if ($slot !== null) {
                $existingByslotId[$slot->getId()] = true;
            }
        }

        // Pose 'unavailable' UNIQUEMENT sur les slots non déjà positionnés.
        $count = 0;
        $slotRows = $this->schedule->buildWeek($monday);
        foreach ($slotRows as $slotRow) {
            if (!empty($slotRow['isCancelled'])) continue;
            $slotId = $slotRow['id'] ?? null;
            $templateId = $slotRow['templateId'] ?? null;

            // Slot déjà matérialisé et positionné : skip.
            if ($slotId !== null && isset($existingByslotId[$slotId])) continue;

            if ($slotId !== null) {
                $slot = $this->slots->find($slotId);
                if ($slot !== null) {
                    $this->service->setForSlot($user, $slot, StaffPresence::STATUS_UNAVAILABLE, null);
                    $count++;
                }
            } elseif ($templateId !== null) {
                $template = $this->templates->find($templateId);
                if ($template !== null) {
                    // Un template virtuel n'a jamais de présence pré-existante
                    // (par définition il n'a pas d'id de slot matérialisé pour
                    // cet user), on peut donc marquer sans check supplémentaire.
                    $this->service->setForTemplate($user, $template, $monday, StaffPresence::STATUS_UNAVAILABLE, null);
                    $count++;
                }
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'week' => $monday->format('Y-m-d'),
            'markedCount' => $count,
        ]);
    }

    /**
     * Retire la déclaration d'indisponibilité pour la semaine.
     * Body : { week: "YYYY-MM-DD" }
     */
    #[Route('/api/me/staff-presence/unavailable', name: 'api_staff_presence_unset_unavailable', methods: ['DELETE'])]
    public function unsetUnavailable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $weekRaw = (string) $request->query->get('week', '');
        try {
            $week = $weekRaw !== '' ? new \DateTimeImmutable($weekRaw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(['error' => 'week invalide'], Response::HTTP_BAD_REQUEST);
        }
        $monday = WeeklyScheduleService::snapToMonday($week);

        $existing = $this->unavailabilities->findOneByUserAndWeek($user, $monday);
        if ($existing !== null) {
            $this->em->remove($existing);
        }

        // Retire aussi les StaffPresence « unavailable » de la semaine —
        // celles laissées par l'user en manuel sur un slot particulier
        // sont préservées ? Non : le geste global « je redeviens dispo »
        // efface l'ensemble des marqueurs unavailable de la semaine ; les
        // choix « scheduled » posés manuellement restent inchangés.
        foreach ($this->presences->findByUserAndWeek($user, $monday) as $p) {
            if ($p->getStatus() === StaffPresence::STATUS_UNAVAILABLE) {
                $this->em->remove($p);
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'week' => $monday->format('Y-m-d'),
            'unavailable' => false,
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

        // Statut acceptés côté mobile : « Je serai là » (scheduled) ou
        // « Je ne serai pas là » (unavailable). La validation effective
        // (status='attended') reste une prérogative backend uniquement —
        // toute tentative 'attended' du client est downgradée vers scheduled.
        $rawStatus = (string) ($payload['status'] ?? StaffPresence::STATUS_SCHEDULED);
        $status = in_array($rawStatus, [
            StaffPresence::STATUS_SCHEDULED,
            StaffPresence::STATUS_UNAVAILABLE,
        ], true) ? $rawStatus : StaffPresence::STATUS_SCHEDULED;
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

        // Se positionner « Je serai là » sur un créneau annule
        // implicitement la déclaration « non dispo cette semaine » : la
        // case Non Dispo se déselectionne au prochain refresh.
        if ($status === StaffPresence::STATUS_SCHEDULED) {
            $monday = $presence->getWeekStartsAt();
            $existingUnav = $this->unavailabilities->findOneByUserAndWeek($user, $monday);
            if ($existingUnav !== null) {
                $this->em->remove($existingUnav);
            }
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
     * Semaine type personnelle : tous les créneaux actifs de la semaine
     * type du club, avec un booléen « je suis présent ici en temps
     * normal ». Indépendant de toute semaine précise — configurable une
     * fois pour la saison (voir applyTemplate() pour l'appliquer à une
     * semaine réelle).
     *
     * findActiveOrdered() renvoie TOUS les templates actifs, y compris
     * ceux d'anciennes saisons clonées vers la saison suivante
     * (TrainingSlotTemplate::duplicateForSeason) — sans filtre, un même
     * créneau apparaîtrait en double (l'ancien ET le nouveau). On ne
     * garde que ceux applicables à la semaine courante, exactement comme
     * WeeklyScheduleService::buildWeek() pour la vue hebdo normale.
     */
    #[Route('/api/me/staff-presence/template', name: 'api_staff_presence_template_get', methods: ['GET'])]
    public function getTemplate(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $present = $this->presenceTemplates->findPresentTemplateIds($user);
        $thisWeek = WeeklyScheduleService::snapToMonday(new \DateTimeImmutable('today'));

        $slots = array_values(array_map(function ($tpl) use ($present) {
            return [
                'slotTemplateId' => $tpl->getId(),
                'dayOfWeek' => $tpl->getDayOfWeek(),
                'startTime' => $tpl->getStartTime()->format('H:i'),
                'durationMinutes' => $tpl->getDurationMinutes(),
                'sport' => $tpl->getSport()->value,
                'sportLabel' => $tpl->getSport()->label(),
                'sportIcon' => $tpl->getSport()->icon(),
                'sportColor' => $tpl->getSport()->color(),
                'title' => $tpl->getTitle(),
                'location' => $tpl->getLocation(),
                'present' => isset($present[$tpl->getId()]),
            ];
        }, array_filter(
            $this->templates->findActiveOrdered(),
            fn ($tpl) => $tpl->appliesOn($thisWeek),
        )));

        return new JsonResponse(['slots' => $slots]);
    }

    /**
     * Coche/décoche un créneau de ma semaine type.
     * Body : { slotTemplateId: int, present: bool }
     */
    #[Route('/api/me/staff-presence/template', name: 'api_staff_presence_template_set', methods: ['POST'])]
    public function setTemplateSlot(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $templateId = isset($payload['slotTemplateId']) ? (int) $payload['slotTemplateId'] : 0;
        $present = !empty($payload['present']);

        $template = $templateId > 0 ? $this->templates->find($templateId) : null;
        if ($template === null) {
            throw $this->createNotFoundException('Créneau introuvable.');
        }

        $existing = $this->presenceTemplates->findOneByUserAndSlotTemplate($user, $template);
        if ($present && $existing === null) {
            $this->em->persist(new StaffPresenceTemplate($user, $template));
        } elseif (!$present && $existing !== null) {
            $this->em->remove($existing);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'slotTemplateId' => $templateId, 'present' => $present]);
    }

    /**
     * Positionne la présence du user sur une semaine précise d'après sa
     * semaine type : pour chaque créneau actif (non annulé) de la
     * semaine, force 'scheduled' si marqué présent dans le template,
     * 'unavailable' sinon — écrasant systématiquement tout choix déjà
     * posé sur ce créneau (y compris une indisponibilité explicite) et
     * retirant le marqueur d'indisponibilité globale de la semaine.
     *
     * Body : { week: "YYYY-MM-DD" }
     */
    #[Route('/api/me/staff-presence/apply-template', name: 'api_staff_presence_apply_template', methods: ['POST'])]
    public function applyTemplate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->ensureStaff($user);

        $payload = json_decode($request->getContent() ?: '{}', true);
        $weekRaw = is_array($payload) ? (string) ($payload['week'] ?? '') : '';
        try {
            $week = $weekRaw !== '' ? new \DateTimeImmutable($weekRaw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(['error' => 'week invalide'], Response::HTTP_BAD_REQUEST);
        }
        $monday = WeeklyScheduleService::snapToMonday($week);

        $present = $this->presenceTemplates->findPresentTemplateIds($user);
        if ($present === []) {
            return new JsonResponse(
                ['error' => 'Configurez d\'abord votre semaine type (Ma semaine type).'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
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
                    $this->service->setForSlot($user, $slot, $status);
                }
            } else {
                $template = $this->templates->find($templateId);
                if ($template !== null) {
                    $this->service->setForTemplate($user, $template, $monday, $status);
                }
            }
            if ($status === StaffPresence::STATUS_SCHEDULED) $scheduledCount++;
            else $unavailableCount++;
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'week' => $monday->format('Y-m-d'),
            'scheduledCount' => $scheduledCount,
            'unavailableCount' => $unavailableCount,
        ]);
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
