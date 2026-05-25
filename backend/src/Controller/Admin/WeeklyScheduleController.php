<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use App\Enum\Sport;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Service\Training\WeeklyScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page d'édition par semaine : permet de visualiser la semaine type
 * "projetée" sur une semaine précise, et d'annuler / modifier / ajouter
 * des créneaux pour cette semaine uniquement.
 */
#[IsGranted('ROLE_COACH')]
class WeeklyScheduleController extends AbstractController
{
    public function __construct(
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly TrainingSlotRepository $slots,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/training-schedule', name: 'admin_training_schedule')]
    public function index(Request $request): Response
    {
        $week = $this->parseWeek($request->query->get('week'));
        $rows = $this->schedule->buildWeek($week);

        return $this->render('admin/training_schedule.html.twig', [
            'week' => $week,
            'weekIso' => $week->format('o-\WW'),
            'weekHuman' => $this->humanWeekLabel($week),
            'prev' => $week->modify('-7 days')->format('Y-m-d'),
            'next' => $week->modify('+7 days')->format('Y-m-d'),
            'today' => (new \DateTimeImmutable('today'))->modify('monday this week')->format('Y-m-d'),
            'rows' => $rows,
            'sportChoices' => Sport::choices(),
        ]);
    }

    /**
     * Annule (ou réactive) un créneau récurrent pour la semaine donnée.
     * On matérialise le slot s'il n'existe pas encore.
     */
    #[Route('/admin/training-schedule/cancel', name: 'admin_training_schedule_cancel', methods: ['POST'])]
    public function cancel(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $templateId = (int) $request->request->get('templateId');
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw $this->createNotFoundException();
        }

        $slot = $this->schedule->materializeOverride($week, $template);
        $slot->setIsCancelled(true);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Créneau « %s » annulé pour la semaine du %s.',
            $template->getTitle(),
            $week->format('d/m/Y'),
        ));

        return $this->redirectToRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    #[Route('/admin/training-schedule/restore', name: 'admin_training_schedule_restore', methods: ['POST'])]
    public function restore(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $slotId = (int) $request->request->get('slotId');
        $slot = $this->slots->find($slotId);
        if ($slot === null || $slot->getTemplate() === null) {
            throw $this->createNotFoundException();
        }

        // Restaurer = supprimer l'override (le template virtuel reprend la main).
        $this->em->remove($slot);
        $this->em->flush();

        $this->addFlash('success', 'Créneau restauré (la semaine type s\'applique à nouveau).');
        return $this->redirectToRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    /**
     * Formulaire d'édition d'un créneau pour la semaine (override ou occasionnel).
     * Si templateId est fourni et qu'aucun override n'existe encore, matérialise.
     */
    #[Route('/admin/training-schedule/edit', name: 'admin_training_schedule_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $week = $this->parseWeek($request->query->get('week') ?? $request->request->get('week'));
        $slotId = $this->intOrNull($request->query->get('slotId') ?? $request->request->get('slotId'));
        $templateId = $this->intOrNull($request->query->get('templateId') ?? $request->request->get('templateId'));

        if ($slotId !== null) {
            $slot = $this->slots->find($slotId);
            if ($slot === null) {
                throw $this->createNotFoundException();
            }
        } elseif ($templateId !== null) {
            $template = $this->templates->find($templateId);
            if ($template === null) {
                throw $this->createNotFoundException();
            }
            $slot = $this->schedule->materializeOverride($week, $template);
            // Persisté mais pas encore flushé (le flush attend la soumission ci-dessous).
        } else {
            // Création d'un créneau occasionnel (vacances, etc.)
            $slot = (new TrainingSlot())
                ->setWeekStartsAt($week);
            $this->em->persist($slot);
        }

        if ($request->isMethod('POST')) {
            $this->validateCsrf($request, 'schedule_edit');
            $this->applyForm($slot, $request);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Créneau « %s » mis à jour pour la semaine du %s.',
                $slot->getTitle(),
                $week->format('d/m/Y'),
            ));
            return $this->redirectToRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
        }

        return $this->render('admin/training_schedule_edit.html.twig', [
            'slot' => $slot,
            'week' => $week,
            'weekHuman' => $this->humanWeekLabel($week),
            'isOccasional' => $slot->getTemplate() === null,
            'sportChoices' => Sport::choices(),
        ]);
    }

    #[Route('/admin/training-schedule/delete', name: 'admin_training_schedule_delete', methods: ['POST'])]
    public function delete(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $slotId = (int) $request->request->get('slotId');
        $slot = $this->slots->find($slotId);
        if ($slot === null) {
            throw $this->createNotFoundException();
        }
        if ($slot->getTemplate() !== null) {
            throw new \LogicException('Utilisez "restore" pour annuler un override.');
        }
        $this->em->remove($slot);
        $this->em->flush();

        $this->addFlash('success', 'Créneau occasionnel supprimé.');
        return $this->redirectToRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    // -------- helpers --------

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === '0') {
            return null;
        }
        $i = (int) $v;
        return $i > 0 ? $i : null;
    }

    private function parseWeek(?string $raw): \DateTimeImmutable
    {
        try {
            $d = $raw && $raw !== '' ? new \DateTimeImmutable($raw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $d = new \DateTimeImmutable('today');
        }
        return WeeklyScheduleService::snapToMonday($d);
    }

    private function applyForm(TrainingSlot $slot, Request $request): void
    {
        $get = static fn (string $k, mixed $default = null) => $request->request->get($k, $default);

        $sport = Sport::tryFrom((string) $get('sport', 'autre')) ?? Sport::Autre;
        $day = max(1, min(7, (int) $get('dayOfWeek', 1)));
        $startTime = (string) $get('startTime', '18:30');
        $duration = max(5, min(600, (int) $get('durationMinutes', 60)));

        $slot
            ->setDayOfWeek($day)
            ->setStartTime(new \DateTimeImmutable($startTime))
            ->setDurationMinutes($duration)
            ->setSport($sport)
            ->setTitle(trim((string) $get('title', '')))
            ->setLocation(trim((string) $get('location', '')))
            ->setDescription(trim((string) $get('description', '')) ?: null)
            ->setIsCancelled((bool) $get('isCancelled', false));
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
