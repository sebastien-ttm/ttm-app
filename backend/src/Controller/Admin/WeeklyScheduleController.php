<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use App\Enum\Sport;
use App\Repository\TrainingSlotAttachmentRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Service\Training\AttachmentService;
use App\Service\Training\WeeklyScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
        private readonly TrainingSlotAttachmentRepository $attachments,
        private readonly AttachmentService $attachmentService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Redirige vers une route admin. On utilise les routes directes ;
     * la page de destination utilise un layout standalone qui ne dépend
     * pas du contexte EasyAdmin.
     */
    private function redirectToAdminRoute(string $route, array $params = []): RedirectResponse
    {
        return $this->redirectToRoute($route, $params);
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
    /**
     * Annule un créneau pour la semaine.
     * Accepte templateId (matérialise + annule) OU slotId (annule override/occasionnel).
     */
    #[Route('/admin/training-schedule/cancel', name: 'admin_training_schedule_cancel', methods: ['POST'])]
    public function cancel(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $slotId = $this->intOrNull($request->request->get('slotId'));
        $templateId = $this->intOrNull($request->request->get('templateId'));

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
        } else {
            throw $this->createNotFoundException();
        }

        $slot->setIsCancelled(true);
        $this->em->flush();

        $this->addFlash('success', sprintf('Créneau « %s » annulé pour cette semaine.', $slot->getTitle()));
        return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    /**
     * Réactive ou restaure un créneau annulé / modifié.
     *  - Override annulé : supprime l'override pour revenir au template virtuel.
     *  - Override modifié non annulé : supprime aussi (= restaurer le template).
     *  - Occasionnel annulé : remet juste isCancelled=false.
     */
    #[Route('/admin/training-schedule/restore', name: 'admin_training_schedule_restore', methods: ['POST'])]
    public function restore(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $slotId = (int) $request->request->get('slotId');
        $slot = $this->slots->find($slotId);
        if ($slot === null) {
            throw $this->createNotFoundException();
        }

        if ($slot->getTemplate() !== null) {
            // Override : supprime pour revenir au template virtuel
            $this->em->remove($slot);
            $this->addFlash('success', 'Créneau restauré (la semaine type s\'applique à nouveau).');
        } else {
            // Occasionnel : juste réactiver
            $slot->setIsCancelled(false);
            $this->addFlash('success', 'Créneau occasionnel réactivé.');
        }
        $this->em->flush();

        return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    /**
     * Annule TOUS les créneaux de la semaine en une action
     * (pratique pour les semaines de vacances).
     */
    #[Route('/admin/training-schedule/cancel-all', name: 'admin_training_schedule_cancel_all', methods: ['POST'])]
    public function cancelAll(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $count = 0;

        // 1) Matérialise + annule tous les templates actifs qui s'appliquent
        foreach ($this->templates->findActiveOrdered() as $tpl) {
            if (!$tpl->appliesOn($week)) {
                continue;
            }
            $slot = $this->schedule->materializeOverride($week, $tpl);
            if (!$slot->isCancelled()) {
                $slot->setIsCancelled(true);
                $count++;
            }
        }

        // 2) Annule les occasionnels existants
        foreach ($this->slots->findForWeek($week) as $s) {
            if ($s->getTemplate() === null && !$s->isCancelled()) {
                $s->setIsCancelled(true);
                $count++;
            }
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('%d créneau(x) annulé(s) pour cette semaine.', $count));
        return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    /**
     * Réactive tous les créneaux annulés de la semaine (overrides + occasionnels).
     * Pour les overrides annulés, on supprime l'override (le template reprend).
     */
    #[Route('/admin/training-schedule/restore-all', name: 'admin_training_schedule_restore_all', methods: ['POST'])]
    public function restoreAll(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $count = 0;

        foreach ($this->slots->findForWeek($week) as $s) {
            if (!$s->isCancelled()) {
                continue;
            }
            if ($s->getTemplate() !== null) {
                // Override annulé → supprime, le template reprend
                $this->em->remove($s);
            } else {
                // Occasionnel annulé → réactive
                $s->setIsCancelled(false);
            }
            $count++;
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('%d créneau(x) réactivé(s).', $count));
        return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
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
            // Premier flush : assure que le slot a un ID avant d'attacher des fichiers
            $this->em->flush();

            // Pièces jointes (multipart)
            /** @var UploadedFile[] $files */
            $files = $request->files->all('attachments');
            $attachErrors = [];
            foreach (array_filter($files) as $file) {
                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    continue;
                }
                try {
                    $this->attachmentService->upload($slot, $file);
                } catch (\Throwable $e) {
                    $attachErrors[] = $file->getClientOriginalName().' : '.$e->getMessage();
                }
            }
            if ($files !== []) {
                $this->em->flush();
            }
            foreach ($attachErrors as $err) {
                $this->addFlash('error', 'Erreur upload : '.$err);
            }

            $this->addFlash('success', sprintf(
                'Créneau « %s » mis à jour pour la semaine du %s.',
                $slot->getTitle(),
                $week->format('d/m/Y'),
            ));
            return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
        }

        return $this->render('admin/training_schedule_edit.html.twig', [
            'slot' => $slot,
            'week' => $week,
            'weekHuman' => $this->humanWeekLabel($week),
            'isOccasional' => $slot->getTemplate() === null,
            'sportChoices' => Sport::choices(),
        ]);
    }

    /** Téléchargement d'une PJ (réservé aux admins/coachs via IsGranted en haut). */
    #[Route('/admin/training-schedule/attachment/{id}/download', name: 'admin_training_schedule_attachment_download', requirements: ['id' => '\d+'])]
    public function attachmentDownload(int $id): BinaryFileResponse
    {
        $att = $this->attachments->find($id);
        if ($att === null) {
            throw $this->createNotFoundException();
        }
        $path = $this->attachmentService->absolutePath($att);
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }
        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $att->getOriginalName(),
        );
        $resp->headers->set('Content-Type', $att->getMimeType());
        return $resp;
    }

    #[Route('/admin/training-schedule/attachment/{id}/delete', name: 'admin_training_schedule_attachment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attachmentDelete(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $att = $this->attachments->find($id);
        if ($att === null) {
            throw $this->createNotFoundException();
        }
        $slot = $att->getSlot();
        $week = $slot->getWeekStartsAt();
        $this->attachmentService->remove($att);
        $this->em->flush();

        $this->addFlash('success', 'Pièce jointe supprimée.');
        return $this->redirectToAdminRoute('admin_training_schedule_edit', [
            'week' => $week->format('Y-m-d'),
            'slotId' => $slot->getId(),
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
        return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
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
