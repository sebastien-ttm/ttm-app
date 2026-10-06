<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\EventCheckIn;
use App\Entity\User;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventCheckInRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Feuille d'émargement d'un événement soumis au vote : part des votes
 * de présence (« présent » en tête, puis « peut-être », « absent »),
 * et permet d'émarger aussi un adhérent qui n'a pas voté (recherche).
 * Un appui sur le bouton coche / décoche la présence sans recharger la
 * page (utilisable sur téléphone le jour J).
 */
#[IsGranted('ROLE_EDITEUR')]
class EventCheckInController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'event_check_in';
    private const VOTE_ORDER = ['yes' => 0, 'maybe' => 1, 'no' => 2, 'none' => 3];

    public function __construct(
        private readonly EventRepository $events,
        private readonly EventAttendanceRepository $attendances,
        private readonly EventCheckInRepository $checkIns,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/event-attendance/{id}/emargement', name: 'admin_event_check_in', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sheet(int $id, Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_event_check_in')) {
            return $r;
        }
        $event = $this->findEvent($id);

        $checkIns = $this->checkIns->findByEventIndexedByUser($event);

        /** @var array<int, array{user: User, vote: string}> $people */
        $people = [];
        foreach ($this->attendances->findByEventWithUser($event) as $a) {
            $people[$a->getUser()->getId()] = ['user' => $a->getUser(), 'vote' => $a->getStatus()->value];
        }
        // Adhérents sans vote : affichés seulement en recherche (ou s'ils
        // sont déjà émargés), pour ne pas noyer la liste des votants.
        foreach ($this->users->findActiveAdherentsForRecap() as $u) {
            $people[$u->getId()] ??= ['user' => $u, 'vote' => 'none'];
        }
        foreach ($checkIns as $userId => $c) {
            $people[$userId] ??= ['user' => $c->getUser(), 'vote' => 'none'];
        }

        $rows = [];
        foreach ($people as $userId => $p) {
            $rows[] = [
                'id' => $userId,
                'nom' => $p['user']->getNom(),
                'prenom' => $p['user']->getPrenom(),
                'vote' => $p['vote'],
                'state' => self::state($checkIns[$userId] ?? null),
            ];
        }
        usort($rows, fn ($a, $b) => [self::VOTE_ORDER[$a['vote']], mb_strtolower($a['nom']), mb_strtolower($a['prenom'])]
            <=> [self::VOTE_ORDER[$b['vote']], mb_strtolower($b['nom']), mb_strtolower($b['prenom'])]);

        return $this->render('admin/event_check_in.html.twig', [
            'event' => $event,
            'rows' => $rows,
            'checkedCount' => count($checkIns),
            'detailUrl' => $this->adminRoute('admin_event_attendance_detail', ['id' => $event->getId()]),
            'csvUrl' => $this->adminRoute('admin_event_attendance_detail_csv', ['id' => $event->getId()]),
        ]);
    }

    /** Coche ou décoche la présence d'un adhérent. JSON : checked=1|0. */
    #[Route('/admin/event-attendance/{id}/emargement/{userId}', name: 'admin_event_check_in_toggle', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function toggle(int $id, int $userId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $event = $this->findEvent($id);
        $user = $this->users->find($userId);
        if ($user === null) {
            throw $this->createNotFoundException('Adhérent introuvable.');
        }

        $checkIn = $this->checkIns->findOneByEventAndUser($event, $user);
        $wanted = $request->request->get('checked') === '1';
        if ($wanted && $checkIn === null) {
            /** @var User $by */
            $by = $this->getUser();
            $checkIn = new EventCheckIn($event, $user, $by);
            $this->em->persist($checkIn);
            $this->em->flush();
        } elseif (!$wanted && $checkIn !== null) {
            $this->em->remove($checkIn);
            $this->em->flush();
            $checkIn = null;
        }

        return new JsonResponse(self::state($checkIn));
    }

    private function findEvent(int $id): Event
    {
        $event = $this->events->find($id);
        if ($event === null || !$event->isVoteEnabled()) {
            throw $this->createNotFoundException('Événement introuvable ou non soumis au vote.');
        }
        return $event;
    }

    /** @return array{checked: bool, at: ?string, by: ?string} */
    private static function state(?EventCheckIn $c): array
    {
        return [
            'checked' => $c !== null,
            'at' => $c?->getCheckedAt()->format('H:i'),
            'by' => $c?->getCheckedBy()?->getFullName(),
        ];
    }

    /** Voir doc dans EventAttendanceReportController::adminRoute(). */
    private function adminRoute(string $routeName, array $params = []): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setRoute($routeName, $params)
            ->generateUrl();
    }
}
