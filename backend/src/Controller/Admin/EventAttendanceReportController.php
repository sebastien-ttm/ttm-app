<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Enum\AttendanceStatus;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 *   (présents / peut-être / absents) + export CSV.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class EventAttendanceReportController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventAttendanceRepository $attendances,
    ) {
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
            return $this->redirectToRoute('admin_event_attendance_index');
        }

        $all = $this->attendances->findByEventWithUser($event);
        $lists = ['yes' => [], 'maybe' => [], 'no' => []];
        foreach ($all as $a) {
            $lists[$a->getStatus()->value][] = $a;
        }

        return $this->render('admin/event_attendance_detail.html.twig', [
            'event' => $event,
            'yes' => $lists['yes'],
            'maybe' => $lists['maybe'],
            'no' => $lists['no'],
            'total' => count($all),
        ]);
    }

    #[Route('/admin/event-attendance/{id}.csv', name: 'admin_event_attendance_detail_csv', requirements: ['id' => '\d+'])]
    public function detailCsv(int $id): StreamedResponse
    {
        $event = $this->events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Événement introuvable.');
        }
        $all = $this->attendances->findByEventWithUser($event);

        $response = new StreamedResponse(function () use ($event, $all): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Événement', $event->getTitle()], ';');
            fputcsv($out, ['Date', $event->getStartsAt()->format('d/m/Y H:i')], ';');
            fputcsv($out, [], ';');
            fputcsv($out, ['Statut', 'Nom', 'Prénom', 'N° licence', 'Email', 'Voté le'], ';');
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
                ], ';');
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
