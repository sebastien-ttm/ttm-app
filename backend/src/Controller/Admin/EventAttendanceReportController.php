<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Enum\AttendanceStatus;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventCheckInRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use App\Service\Event\EventAttendanceService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Récap admin des votes de présence sur les événements soumis au vote.
 *
 * - index (`/admin/event-attendance`) : liste des événements votables
 *   (à venir + terminés récemment) avec les 3 compteurs.
 * - detail (`/admin/event-attendance/{id}`) : 3 listes nominatives
 *   (présents / peut-être / absents) + export CSV ; l'administrateur y surcharge
 *   le vote de n'importe quel adhérent (change, ajoute ou retire).
 */
#[IsGranted('ROLE_EDITEUR')]
class EventAttendanceReportController extends AbstractController
{
    private const CSRF_INTENT = 'event_attendance_admin';

    private const STATUS_LABELS = [
        'yes' => 'Présent',
        'maybe' => 'Peut-être',
        'no' => 'Absent',
    ];

    public function __construct(
        private readonly EventRepository $events,
        private readonly EventAttendanceRepository $attendances,
        private readonly EventCheckInRepository $checkIns,
        private readonly UserRepository $users,
        private readonly EventAttendanceService $attendanceService,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    /**
     * Génère une URL vers une de nos routes admin custom en préservant
     * le contexte EasyAdmin (dashboard, menu, i18n…). Sans ça,
     * @EasyAdmin/page/content.html.twig plante avec « attribute "i18n"
     * on a null variable » puisque `ea()` retourne null.
     */
    private function adminRoute(string $routeName, array $params = []): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setRoute($routeName, $params)
            ->generateUrl();
    }

    #[Route('/admin/event-attendance', name: 'admin_event_attendance_index')]
    public function index(): Response
    {
        // Fenêtre pragmatique : on n'affiche pas l'historique intégral
        // — les événements dont la fin remonte à > 60 jours sont archivés.
        $from = (new \DateTimeImmutable('today'))->modify('-60 days');
        $events = $this->events->findVotable($from);

        $eventIds = array_map(fn (Event $e) => (int) $e->getId(), $events);
        $counts = $this->attendances->countsForEvents($eventIds);
        $checkedCounts = $this->checkIns->countsForEvents($eventIds);

        // Enrichit chaque event de ses compteurs + « total votes »
        $rows = [];
        foreach ($events as $e) {
            $c = $counts[$e->getId()] ?? ['yes' => 0, 'no' => 0, 'maybe' => 0];
            $rows[] = [
                'event' => $e,
                'yes' => $c['yes'],
                'no' => $c['no'],
                'maybe' => $c['maybe'],
                'total' => $c['yes'] + $c['no'] + $c['maybe'],
                'checked' => $checkedCounts[$e->getId()] ?? 0,
                'checkInUrl' => $this->adminRoute('admin_event_check_in', ['id' => $e->getId()]),
                'detailUrl' => $this->adminRoute('admin_event_attendance_detail', ['id' => $e->getId()]),
            ];
        }

        return $this->render('admin/event_attendance_index.html.twig', [
            'rows' => $rows,
        ]);
    }

    #[Route('/admin/event-attendance/{id}', name: 'admin_event_attendance_detail', requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $event = $this->events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Événement introuvable.');
        }
        if (!$event->isVoteEnabled()) {
            $this->addFlash('warning', 'Cet événement n\'est pas soumis au vote.');
            return $this->redirect($this->adminRoute('admin_event_attendance_index'));
        }

        $all = $this->attendances->findByEventWithUser($event);
        $lists = ['yes' => [], 'maybe' => [], 'no' => []];
        $currentVotes = [];
        foreach ($all as $a) {
            $lists[$a->getStatus()->value][] = $a;
            $currentVotes[$a->getUser()->getId()] = self::STATUS_LABELS[$a->getStatus()->value] ?? null;
        }

        // Surcharge réservée à l'administrateur : liste de tous les adhérents actifs (avec leur vote actuel).
        $canOverride = $this->isGranted('ROLE_ADMIN');
        $members = [];
        if ($canOverride) {
            foreach ($this->users->findActiveAdherentsForRecap() as $u) {
                $members[] = [
                    'id' => $u->getId(),
                    'label' => mb_strtoupper((string) $u->getNom()).' '.$u->getPrenom(),
                    'current' => $currentVotes[$u->getId()] ?? null,
                ];
            }
        }

        return $this->render('admin/event_attendance_detail.html.twig', [
            'event' => $event,
            'yes' => $lists['yes'],
            'maybe' => $lists['maybe'],
            'no' => $lists['no'],
            'total' => count($all),
            'canOverride' => $canOverride,
            'members' => $members,
            'voteUrl' => $this->generateUrl('admin_event_attendance_set_vote', ['id' => $event->getId()]),
            'indexUrl' => $this->adminRoute('admin_event_attendance_index'),
            'csvUrl' => $this->adminRoute('admin_event_attendance_detail_csv', ['id' => $event->getId()]),
            'checkInUrl' => $this->adminRoute('admin_event_check_in', ['id' => $event->getId()]),
            'checkedCount' => count($this->checkIns->findByEventIndexedByUser($event)),
        ]);
    }

    /**
     * Surcharge par l'administrateur du vote de présence d'un adhérent : change, ajoute (l'adhérent n'avait pas
     * voté) ou retire (`status` = none) son vote. Même effet que s'il l'avait fait lui-même depuis l'appli,
     * y compris sur le groupe d'adhérents lié à l'événement.
     */
    #[Route('/admin/event-attendance/{id}/vote', name: 'admin_event_attendance_set_vote', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function setVote(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $event = $this->events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Événement introuvable.');
        }
        if (!$event->isVoteEnabled()) {
            $this->addFlash('warning', 'Cet événement n\'est pas soumis au vote.');

            return $this->redirect($this->adminRoute('admin_event_attendance_index'));
        }
        $back = $this->redirect($this->adminRoute('admin_event_attendance_detail', ['id' => $id]));

        $user = $this->users->find((int) $request->request->get('user_id', 0));
        if ($user === null) {
            $this->addFlash('danger', 'Adhérent introuvable.');

            return $back;
        }

        $raw = (string) $request->request->get('status', '');
        $status = $raw === 'none' ? null : AttendanceStatus::tryFrom($raw);
        if ($raw !== 'none' && $status === null) {
            $this->addFlash('danger', 'Statut invalide.');

            return $back;
        }

        $this->attendanceService->setVote($event, $user, $status);

        $this->addFlash('success', $status === null
            ? sprintf('Vote de %s retiré.', $user->getFullName())
            : sprintf('Vote de %s : %s.', $user->getFullName(), self::STATUS_LABELS[$status->value] ?? $status->value));

        return $back;
    }

    #[Route('/admin/event-attendance/{id}.csv', name: 'admin_event_attendance_detail_csv', requirements: ['id' => '\d+'])]
    public function detailCsv(int $id): StreamedResponse
    {
        $event = $this->events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Événement introuvable.');
        }
        $all = $this->attendances->findByEventWithUser($event);
        $checkIns = $this->checkIns->findByEventIndexedByUser($event);

        $response = new StreamedResponse(function () use ($event, $all, $checkIns): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Événement', $event->getTitle()], ';');
            fputcsv($out, ['Date', $event->getStartsAt()->format('d/m/Y H:i')], ';');
            fputcsv($out, [], ';');
            fputcsv($out, ['Statut', 'Nom', 'Prénom', 'N° licence', 'Email', 'Voté le', 'Émargé'], ';');
            $order = [
                AttendanceStatus::Yes->value => 'Présent',
                AttendanceStatus::Maybe->value => 'Peut-être',
                AttendanceStatus::No->value => 'Absent',
            ];
            // Tri par statut (Présent → Peut-être → Absent), puis nom
            usort($all, function (EventAttendance $a, EventAttendance $b) use ($order) {
                $ra = array_search($a->getStatus()->value, array_keys($order), true);
                $rb = array_search($b->getStatus()->value, array_keys($order), true);
                if ($ra !== $rb) return $ra <=> $rb;
                return strcmp((string) $a->getUser()->getNom(), (string) $b->getUser()->getNom());
            });
            foreach ($all as $a) {
                $u = $a->getUser();
                fputcsv($out, [
                    $order[$a->getStatus()->value] ?? $a->getStatus()->value,
                    $u->getNom(),
                    $u->getPrenom(),
                    $u->getNumLicence(),
                    $u->getEmail(),
                    $a->getUpdatedAt()->format('d/m/Y H:i'),
                    isset($checkIns[$u->getId()]) ? 'oui' : '',
                ], ';');
                unset($checkIns[$u->getId()]);
            }
            // Émargés sans avoir voté.
            foreach ($checkIns as $c) {
                $u = $c->getUser();
                fputcsv($out, ['Sans vote', $u->getNom(), $u->getPrenom(), $u->getNumLicence(), $u->getEmail(), '', 'oui'], ';');
            }
            fclose($out);
        });

        $slug = preg_replace('/[^a-z0-9]+/i', '-', $event->getTitle()) ?: 'event';
        $filename = sprintf('votes-%s-%s.csv', strtolower(trim($slug, '-')), date('Ymd-Hi'));

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;
    }
}
