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
#[IsGranted('ROLE_ADMIN')]
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
        ]);
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
