<?php

namespace App\Controller\Admin;

use App\Entity\Stage;
use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Enum\Sport;
use App\Repository\StaffPresenceRepository;
use App\Repository\TrainingSlotAttachmentRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Repository\UserRepository;
use App\Service\Training\AttachmentService;
use App\Service\Training\TrainingRegistrationService;
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
#[IsGranted('ROLE_ENTRAINEUR')]
class WeeklyScheduleController extends AbstractController
{
    public function __construct(
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly TrainingSlotRepository $slots,
        private readonly TrainingSlotAttachmentRepository $attachments,
        private readonly AttachmentService $attachmentService,
        private readonly StaffPresenceRepository $presences,
        private readonly TrainingRegistrationService $registrationService,
        private readonly UserRepository $users,
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

        // Créneaux sur inscription : inscrits de la semaine (noms, dans l'ordre d'inscription).
        $registrants = $this->registrationService->registrantsForWeek($week);
        foreach ($rows as &$row) {
            $names = array_map(static fn (User $u) => $u->getFullName(), $registrants[TrainingRegistrationService::occurrenceKey($row)] ?? []);
            $row['registrants'] = $names;
            $row['registrantsCount'] = count($names);
        }
        unset($row);
        $hasRegistration = array_filter($rows, static fn (array $r) => $r['registrationRequired']) !== [];

        return $this->render('admin/training_schedule.html.twig', [
            'hasRegistration' => $hasRegistration,
            'registrationUnlocked' => $hasRegistration && $this->registrationService->isUnlocked($week),
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

        $template = $slot->getTemplate();
        if ($template !== null) {
            // Stratégie hybride :
            //  - Si le slot porte des présences staff OU des pièces jointes,
            //    on NE supprime PAS la ligne (sinon cascade DELETE des
            //    présences via FK onDelete=CASCADE). À la place, on
            //    réinitialise les champs aux valeurs du template (soft
            //    reset). differsMateriallyFromTemplate() retournera false
            //    → le créneau redevient « virtuel » dans l'UI, sans badge
            //    « Modifié » ni bouton « Restaurer ».
            //  - Sinon (override pur, aucune donnée rattachée), suppression
            //    franche comme avant : la ligne disparaît de la BDD.
            $hasPresences = $this->presences->countForSlot($slot) > 0;
            $hasAttachments = !$slot->getAttachments()->isEmpty();

            if ($hasPresences || $hasAttachments) {
                $slot->setIsCancelled(false);
                $slot->fillFromTemplate($template);
                $slot->setAudience([]); // hérite du template (cf. convention buildWeek)
                $kept = [];
                if ($hasPresences) $kept[] = sprintf('%d présence(s)', $this->presences->countForSlot($slot));
                if ($hasAttachments) $kept[] = sprintf('%d pièce(s) jointe(s)', $slot->getAttachments()->count());
                $this->addFlash('success', sprintf(
                    'Créneau restauré (modèle réappliqué). %s conservée(s).',
                    implode(' et ', $kept),
                ));
            } else {
                $this->em->remove($slot);
                $this->addFlash('success', 'Créneau restauré (la semaine type s\'applique à nouveau).');
            }
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
        // Création depuis le bouton « ➕ Entraînement dédié » d'un stage : `stageId` rattache le créneau au stage,
        // `date` (AAAA-MM-JJ) fixe la semaine et le jour proposés.
        $stageId = $this->intOrNull($request->query->get('stageId') ?? $request->request->get('stageId'));
        $presetDate = $this->parseDate($request->query->get('date'));
        if ($presetDate !== null && $slotId === null && $templateId === null) {
            $week = WeeklyScheduleService::snapToMonday($presetDate);
        }

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
            if ($stageId !== null) {
                $stage = $this->em->find(Stage::class, $stageId);
                if ($stage === null) {
                    throw $this->createNotFoundException();
                }
                $slot->setStage($stage);
                if ($presetDate !== null) {
                    $slot->setDayOfWeek((int) $presetDate->format('N'));
                }
            }
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
                $slot->getWeekStartsAt()->format('d/m/Y'),
            ));
            // Entraînement dédié à un stage : retour sur le stage.
            if ($slot->getStage() !== null) {
                return $this->redirect($this->stageUrl($slot->getStage()));
            }
            return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
        }

        return $this->render('admin/training_schedule_edit.html.twig', [
            'slot' => $slot,
            'stageUrl' => $slot->getStage() !== null ? $this->stageUrl($slot->getStage()) : null,
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

    /**
     * Supprime un créneau occasionnel de TOUTES les semaines où il se répète (même jour, heure, durée, sport,
     * titre et lieu) : cette semaine et les suivantes (`scope` = from), ou aussi les semaines passées (`scope` = all).
     * Typiquement un créneau resté « occasionnel » chaque semaine après la suppression de sa semaine type.
     * Les présences staff et pièces jointes rattachées à ces créneaux sont supprimées avec eux.
     */
    #[Route('/admin/training-schedule/delete-series', name: 'admin_training_schedule_delete_series', methods: ['POST'])]
    public function deleteSeries(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $slot = $this->slots->find((int) $request->request->get('slotId'));
        if ($slot === null) {
            throw $this->createNotFoundException();
        }
        if ($slot->getTemplate() !== null) {
            throw new \LogicException('Seuls les créneaux occasionnels se suppriment en série.');
        }

        $allWeeks = $request->request->get('scope') === 'all';
        $fromWeek = $allWeeks ? null : $slot->getWeekStartsAt();
        $twins = $this->slots->findOccasionalTwins($slot, $fromWeek);

        $title = $slot->getTitle();
        $presences = 0;
        foreach ($twins as $twin) {
            $presences += $this->presences->countForSlot($twin);
            // Fichiers sur disque d'abord (la ligne BDD part avec le créneau).
            foreach ($twin->getAttachments()->toArray() as $att) {
                $this->attachmentService->remove($att);
            }
            $this->em->remove($twin);
        }
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%d créneau(x) « %s » supprimé(s) (%s)%s.',
            count($twins),
            $title,
            $allWeeks ? 'toutes les semaines' : 'semaine du '.$slot->getWeekStartsAt()->format('d/m/Y').' et suivantes',
            $presences > 0 ? sprintf(', avec %d présence(s) staff', $presences) : '',
        ));

        return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    /**
     * Débloque (`unlocked` = 1) ou rétablit (0) la règle d'inscription de la semaine : débloquée, chaque adhérent peut
     * s'inscrire à DEUX entraînements sur inscription (tant qu'il reste des places) au lieu d'un seul.
     */
    #[Route('/admin/training-schedule/registrations/unlock', name: 'admin_training_registrations_unlock', methods: ['POST'])]
    public function unlockRegistrations(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));

        if ($request->request->get('unlocked') === '1') {
            /** @var User|null $by */
            $by = $this->getUser();
            $this->registrationService->unlock($week, $by);
            $this->addFlash('success', 'Inscriptions débloquées pour cette semaine : chaque adhérent peut s\'inscrire à deux entraînements, tant qu\'il reste des places.');
        } else {
            $this->registrationService->relock($week);
            $this->addFlash('success', 'Une seule inscription par adhérent cette semaine (règle rétablie). Les adhérents déjà inscrits à deux entraînements les gardent.');
        }

        return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
    }

    /**
     * Inscrits d'un créneau sur inscription pour une semaine : liste, désinscription et inscription à la main d'un
     * adhérent (par un entraîneur). Le créneau se désigne par `week` + `templateId` (semaine type) ou `slotId`.
     */
    #[Route('/admin/training-schedule/registrations', name: 'admin_training_registrations', methods: ['GET'])]
    public function registrations(Request $request): Response
    {
        $week = $this->parseWeek($request->query->get('week'));
        $templateId = $this->intOrNull($request->query->get('templateId'));
        $slotId = $this->intOrNull($request->query->get('slotId'));

        $row = $this->registrationService->findRow($this->schedule->buildWeek($week), $templateId, $slotId);
        if ($row === null || !$row['registrationRequired']) {
            $this->addFlash('warning', 'Ce créneau n\'est pas (ou plus) sur inscription pour cette semaine.');

            return $this->redirectToAdminRoute('admin_training_schedule', ['week' => $week->format('Y-m-d')]);
        }

        $registrations = $this->registrationService->registrationsOf($row, $week);
        $registeredIds = array_map(static fn ($r) => $r->getUser()->getId(), $registrations);
        $candidates = array_values(array_filter(
            $this->users->findActiveAdherentsForRecap(),
            static fn (User $u) => !in_array($u->getId(), $registeredIds, true),
        ));

        return $this->render('admin/training_registrations.html.twig', [
            'week' => $week,
            'weekHuman' => $this->humanWeekLabel($week),
            'row' => $row,
            'templateId' => $templateId,
            'slotId' => $slotId,
            'registrations' => $registrations,
            'candidates' => $candidates,
            'limit' => $this->registrationService->limitFor($week),
        ]);
    }

    /**
     * Inscrit (`registered` = 1) ou désinscrit (0) un adhérent à la main. `force` = 1 : dépasse le nombre de places.
     * La limite d'inscriptions par adhérent et par semaine s'applique toujours (voir le déblocage de la semaine).
     */
    #[Route('/admin/training-schedule/registrations/set', name: 'admin_training_registrations_set', methods: ['POST'])]
    public function setRegistration(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'schedule_op');
        $week = $this->parseWeek($request->request->get('week'));
        $templateId = $this->intOrNull($request->request->get('templateId'));
        $slotId = $this->intOrNull($request->request->get('slotId'));
        $params = ['week' => $week->format('Y-m-d')] + ($templateId !== null ? ['templateId' => $templateId] : ['slotId' => $slotId]);

        $user = $this->users->find((int) $request->request->get('userId'));
        if ($user === null) {
            $this->addFlash('danger', 'Adhérent introuvable.');

            return $this->redirectToAdminRoute('admin_training_registrations', $params);
        }

        $registered = $request->request->get('registered') === '1';
        $refusal = $this->registrationService->setRegistered(
            $user,
            $week,
            $templateId,
            $slotId,
            $registered,
            asCoach: true,
            ignoreCapacity: $registered && $request->request->get('force') === '1',
        );
        if ($refusal !== null) {
            $this->addFlash('danger', $refusal['message']);
        } else {
            $this->addFlash('success', sprintf('%s %s.', $user->getFullName(), $registered ? 'inscrit(e)' : 'désinscrit(e)'));
        }

        return $this->redirectToAdminRoute('admin_training_registrations', $params);
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

    /** Page de détail du stage dans l'admin (retour après l'enregistrement d'un entraînement dédié). */
    private function stageUrl(Stage $stage): string
    {
        return $this->generateUrl('admin_dashboard', [
            'crudAction' => 'detail',
            'crudControllerFqcn' => StageCrudController::class,
            'entityId' => $stage->getId(),
        ]);
    }

    /** « AAAA-MM-JJ » → date, ou null si absent / invalide. */
    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
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

        // Entraînement de stage : la date choisie fixe la semaine ET le jour (le stage peut s'étaler sur plusieurs
        // semaines, le formulaire n'a pas de navigation par semaine).
        $date = $slot->getStage() !== null ? $this->parseDate($get('date')) : null;
        if ($date !== null) {
            $slot->setWeekStartsAt(WeeklyScheduleService::snapToMonday($date))->setDayOfWeek((int) $date->format('N'));
        }

        // Sur inscription : réglé ici uniquement pour un créneau occasionnel (celui de la semaine type suit son modèle).
        if ($slot->getTemplate() === null) {
            $capacity = (int) $get('capacity', 0);
            $required = (bool) $get('registrationRequired', false);
            if ($required && $capacity < 1) {
                $required = false;
                $this->addFlash('warning', 'Indiquez le nombre de places pour une inscription : le créneau n\'a pas été mis sur inscription.');
            }
            $slot->setRegistrationRequired($required)->setCapacity($capacity >= 1 ? min(500, $capacity) : null);
        }
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
