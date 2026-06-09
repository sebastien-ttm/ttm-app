<?php

namespace App\Controller\Admin;

use App\Entity\StaffPresence;
use App\Entity\TrainingSlot;
use App\Entity\User;
use App\Enum\Profile;
use App\Enum\Sport;
use App\Repository\StaffPresenceRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Repository\UserRepository;
use App\Service\Training\StaffPresenceService;
use App\Service\Training\WeeklyScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pages backend liées à la présence du staff :
 *  - "Mes présences"            : entraîneur édite ses propres présences
 *                                 + ajoute des créneaux hors entraînement.
 *  - "Présences encadrants"     : vue récap de toutes les présences
 *                                 encadrants pour une semaine, modifiable.
 *  - "Emploi du temps coachs"   : semaine de chaque entraîneur.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class StaffPresenceController extends AbstractController
{
    public function __construct(
        private readonly StaffPresenceRepository $presences,
        private readonly TrainingSlotRepository $slots,
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly UserRepository $users,
        private readonly StaffPresenceService $service,
        private readonly WeeklyScheduleService $schedule,
        private readonly EntityManagerInterface $em,
    ) {
    }

    // ============================================================
    //   1) Mes présences (entraîneur self-service)
    // ============================================================

    #[Route('/admin/staff/my-presences', name: 'admin_staff_my_presences')]
    public function myPresences(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $week = $this->parseWeek($request->query->get('week'));

        $slotRows = $this->schedule->buildWeek($week);
        $myPresences = $this->presences->findByUserAndWeek($user, $week);

        // Map slotId → presence
        $presencesBySlot = [];
        $customTasks = [];
        foreach ($myPresences as $p) {
            if ($p->getSlot() !== null) {
                $presencesBySlot[$p->getSlot()->getId()] = $p;
            } else {
                $customTasks[] = $p;
            }
        }

        return $this->render('admin/staff_my_presences.html.twig', [
            'user' => $user,
            'week' => $week,
            'weekHuman' => $this->humanWeekLabel($week),
            'prev' => $week->modify('-7 days')->format('Y-m-d'),
            'next' => $week->modify('+7 days')->format('Y-m-d'),
            'today' => WeeklyScheduleService::snapToMonday(new \DateTimeImmutable('today'))->format('Y-m-d'),
            'slotRows' => $slotRows,
            'presencesBySlot' => $presencesBySlot,
            'customTasks' => $customTasks,
        ]);
    }

    #[Route('/admin/staff/my-presences/slot', name: 'admin_staff_my_presences_slot', methods: ['POST'])]
    public function setMySlotPresence(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'staff_presence');
        /** @var User $user */
        $user = $this->getUser();
        $week = $this->parseWeek($request->request->get('week'));

        $status = (string) $request->request->get('status', StaffPresence::STATUS_SCHEDULED);
        $slotId = $this->intOrNull($request->request->get('slotId'));
        $templateId = $this->intOrNull($request->request->get('templateId'));
        $remove = $request->request->getBoolean('remove');

        if ($slotId !== null) {
            $slot = $this->slots->find($slotId);
            if ($slot === null) {
                throw $this->createNotFoundException();
            }
            if ($remove) {
                $this->service->unset($user, $slot);
            } else {
                $this->service->setForSlot($user, $slot, $status);
            }
        } elseif ($templateId !== null && !$remove) {
            $template = $this->templates->find($templateId);
            if ($template === null) {
                throw $this->createNotFoundException();
            }
            $this->service->setForTemplate($user, $template, $week, $status);
        }
        $this->em->flush();

        $this->addFlash('success', 'Présence mise à jour.');
        return $this->redirectToRoute('admin_staff_my_presences', ['week' => $week->format('Y-m-d')]);
    }

    #[Route('/admin/staff/my-presences/custom/new', name: 'admin_staff_my_presences_custom_new', methods: ['GET', 'POST'])]
    public function newCustomTask(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $week = $this->parseWeek($request->query->get('week') ?? $request->request->get('week'));

        if ($request->isMethod('POST')) {
            $this->validateCsrf($request, 'staff_presence');
            $title = trim((string) $request->request->get('title', ''));
            $dateRaw = (string) $request->request->get('date', '');
            $timeRaw = (string) $request->request->get('startTime', '18:30');
            $duration = (int) $request->request->get('durationMinutes', 60);

            $errors = [];
            if ($title === '') $errors[] = 'Titre requis.';
            try {
                $date = new \DateTimeImmutable($dateRaw);
            } catch (\Exception) {
                $date = null;
                $errors[] = 'Date invalide.';
            }
            try {
                $time = new \DateTimeImmutable($timeRaw);
            } catch (\Exception) {
                $time = null;
                $errors[] = 'Heure invalide.';
            }

            if ($errors === [] && $date !== null && $time !== null) {
                $task = new StaffPresence($user);
                $task->setTitle($title);
                $task->setDate($date);
                $task->setStartTime($time);
                $task->setDurationMinutes(max(5, min(600, $duration)));
                $task->setNotes(trim((string) $request->request->get('notes', '')) ?: null);
                $this->em->persist($task);
                $this->em->flush();
                $this->addFlash('success', 'Tâche ajoutée à ton emploi du temps.');
                return $this->redirectToRoute('admin_staff_my_presences', [
                    'week' => $task->getWeekStartsAt()->format('Y-m-d'),
                ]);
            }
            foreach ($errors as $e) $this->addFlash('error', $e);
        }

        return $this->render('admin/staff_custom_task_edit.html.twig', [
            'task' => null,
            'week' => $week,
            'weekHuman' => $this->humanWeekLabel($week),
        ]);
    }

    #[Route('/admin/staff/my-presences/custom/{id}/delete', name: 'admin_staff_my_presences_custom_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCustomTask(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'staff_presence');
        /** @var User $user */
        $user = $this->getUser();
        $task = $this->presences->find($id);
        if ($task === null || $task->getUser()->getId() !== $user->getId() || !$task->isCustom()) {
            throw $this->createNotFoundException();
        }
        $week = $task->getWeekStartsAt();
        $this->em->remove($task);
        $this->em->flush();
        $this->addFlash('success', 'Tâche supprimée.');
        return $this->redirectToRoute('admin_staff_my_presences', ['week' => $week->format('Y-m-d')]);
    }

    // ============================================================
    //   2) Supervision : présences encadrants par semaine
    // ============================================================

    #[Route('/admin/staff/supervision/encadrants', name: 'admin_staff_supervision_encadrants')]
    public function supervisionEncadrants(Request $request): Response
    {
        return $this->renderSupervision($request, Profile::Encadrant, 'Présences encadrants');
    }

    #[Route('/admin/staff/supervision/entraineurs', name: 'admin_staff_supervision_entraineurs')]
    public function supervisionEntraineurs(Request $request): Response
    {
        return $this->renderSupervision($request, Profile::Entraineur, 'Emploi du temps entraîneurs');
    }

    private function renderSupervision(Request $request, Profile $profile, string $title): Response
    {
        $week = $this->parseWeek($request->query->get('week'));
        $staff = $this->presences->findActiveStaffByProfile($profile);
        $presencesByUser = $this->presences->findStaffPresencesForWeekGroupedByUser($week);
        $slotRows = $this->schedule->buildWeek($week);

        // Pour chaque encadrant, calcule les créneaux où il n'est PAS
        // encore positionné — permet de proposer une dropdown « ajouter
        // une présence » sans inclure les doublons.
        $availableByUser = [];
        foreach ($staff as $member) {
            $availableByUser[$member->getId()] = $this->computeAvailableSlots(
                $slotRows,
                $presencesByUser[$member->getId()] ?? [],
            );
        }

        return $this->render('admin/staff_supervision.html.twig', [
            'title' => $title,
            'profile' => $profile->value,
            'week' => $week,
            'weekHuman' => $this->humanWeekLabel($week),
            'prev' => $week->modify('-7 days')->format('Y-m-d'),
            'next' => $week->modify('+7 days')->format('Y-m-d'),
            'today' => WeeklyScheduleService::snapToMonday(new \DateTimeImmutable('today'))->format('Y-m-d'),
            'staff' => $staff,
            'presencesByUser' => $presencesByUser,
            'availableByUser' => $availableByUser,
        ]);
    }

    /**
     * Filtre la liste des créneaux de la semaine pour ne garder que ceux
     * où l'utilisateur n'a pas déjà une présence (slot id ou template id).
     * Ignore aussi les créneaux annulés (rien à y faire).
     *
     * @param list<array<string, mixed>> $slotRows
     * @param list<\App\Entity\StaffPresence> $userPresences
     * @return list<array<string, mixed>>
     */
    private function computeAvailableSlots(array $slotRows, array $userPresences): array
    {
        $takenSlotIds = [];
        $takenTemplateIds = [];
        foreach ($userPresences as $p) {
            $s = $p->getSlot();
            if ($s === null) continue;
            $takenSlotIds[$s->getId()] = true;
            $tpl = $s->getTemplate();
            if ($tpl !== null) {
                $takenTemplateIds[$tpl->getId()] = true;
            }
        }

        $available = [];
        foreach ($slotRows as $row) {
            if (!empty($row['isCancelled'])) continue;
            if ($row['id'] !== null && isset($takenSlotIds[$row['id']])) continue;
            if ($row['id'] === null && $row['templateId'] !== null && isset($takenTemplateIds[$row['templateId']])) continue;
            $available[] = $row;
        }
        return $available;
    }

    /**
     * Endpoint admin/entraineur : ajoute une présence pour un encadrant
     * tiers (pas le user connecté). Permet de pré-positionner quelqu'un.
     */
    #[Route('/admin/staff/supervision/add', name: 'admin_staff_supervision_add', methods: ['POST'])]
    public function supervisionAdd(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'staff_presence');

        $userId = (int) $request->request->get('userId');
        $week = $this->parseWeek($request->request->get('week'));
        $status = (string) $request->request->get('status', StaffPresence::STATUS_SCHEDULED);
        $back = (string) $request->request->get('back', 'admin_staff_supervision_encadrants');

        // slotChoice encode soit s:<slotId> (créneau persisté), soit t:<templateId>
        // (créneau virtuel à matérialiser via setForTemplate). Un seul champ
        // dans le form simplifie l'UI et évite la dépendance à du JS inline.
        $slotId = null;
        $templateId = null;
        $choice = (string) $request->request->get('slotChoice', '');
        if (preg_match('/^s:(\d+)$/', $choice, $m)) {
            $slotId = (int) $m[1];
        } elseif (preg_match('/^t:(\d+)$/', $choice, $m)) {
            $templateId = (int) $m[1];
        }

        $user = $this->users->find($userId);
        if ($user === null || !$user->isActive()) {
            throw $this->createNotFoundException();
        }

        if ($slotId !== null) {
            $slot = $this->slots->find($slotId);
            if ($slot === null) {
                throw $this->createNotFoundException();
            }
            $this->service->setForSlot($user, $slot, $status);
        } elseif ($templateId !== null) {
            $template = $this->templates->find($templateId);
            if ($template === null) {
                throw $this->createNotFoundException();
            }
            $this->service->setForTemplate($user, $template, $week, $status);
        } else {
            throw $this->createNotFoundException();
        }
        $this->em->flush();

        $this->addFlash('success', sprintf('Présence ajoutée pour %s.', $user->getFullName()));
        return $this->redirectToRoute($back, ['week' => $week->format('Y-m-d')]);
    }

    /**
     * Endpoint d'édition rapide : un admin/entraineur change le statut d'une
     * présence existante (utilisé depuis la page supervision).
     */
    #[Route('/admin/staff/supervision/update/{id}', name: 'admin_staff_supervision_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supervisionUpdate(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'staff_presence');
        $presence = $this->presences->find($id);
        if ($presence === null) {
            throw $this->createNotFoundException();
        }

        $action = (string) $request->request->get('action', 'set');
        $week = $presence->getWeekStartsAt();
        $back = (string) $request->request->get('back', 'admin_staff_supervision_encadrants');

        if ($action === 'remove') {
            $this->em->remove($presence);
        } else {
            $status = (string) $request->request->get('status', StaffPresence::STATUS_SCHEDULED);
            if (in_array($status, StaffPresence::STATUSES, true)) {
                $presence->setStatus($status);
            }
        }
        $this->em->flush();

        $this->addFlash('success', 'Présence mise à jour.');
        return $this->redirectToRoute($back, ['week' => $week->format('Y-m-d')]);
    }

    // ============================================================
    //   Helpers
    // ============================================================

    private function parseWeek(?string $raw): \DateTimeImmutable
    {
        try {
            $d = $raw && $raw !== '' ? new \DateTimeImmutable($raw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $d = new \DateTimeImmutable('today');
        }
        return WeeklyScheduleService::snapToMonday($d);
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === '0') {
            return null;
        }
        $i = (int) $v;
        return $i > 0 ? $i : null;
    }

    private function validateCsrf(Request $request, string $intent): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($intent, $token)) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }

    private function humanWeekLabel(\DateTimeImmutable $monday): string
    {
        $end = $monday->modify('+6 days');
        $fmtStart = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM');
        $fmtEnd = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM y');
        return sprintf('Semaine du %s au %s', (string) $fmtStart->format($monday), (string) $fmtEnd->format($end));
    }
}
