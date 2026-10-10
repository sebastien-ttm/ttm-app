<?php

namespace App\Controller\Api;

use App\Entity\Event;
use App\Entity\EventCheckIn;
use App\Entity\User;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventCheckInRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Émargement de la présence réelle à un événement soumis au vote, depuis
 * l'espace « Staff » de l'appli (même logique que la feuille d'émargement du
 * backend, EventCheckInController) : la feuille part des votes de présence
 * (oui, peut-être, non) et permet d'émarger aussi un adhérent qui n'a pas voté.
 *
 * Réservé aux entraîneurs, encadrants, membres du CoDir et administrateurs.
 */
#[IsGranted('ROLE_USER')]
class StaffCheckInController extends AbstractController
{
    use StaffOnlyTrait;

    /** Ordre d'affichage : les « oui » d'abord, puis peut-être, non, et ceux qui n'ont pas voté. */
    private const VOTE_ORDER = ['yes' => 0, 'maybe' => 1, 'no' => 2, 'none' => 3];

    public function __construct(
        private readonly EventRepository $events,
        private readonly EventAttendanceRepository $attendances,
        private readonly EventCheckInRepository $checkIns,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Événements soumis au vote à émarger : à venir et en cours d'abord (du plus
     * proche au plus lointain), puis les terminés des 60 derniers jours (du plus
     * récent au plus ancien) — même fenêtre que la page « Votes de présence » du backend.
     */
    #[Route('/api/staff/check-in/events', methods: ['GET'])]
    public function events(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);

        $events = $this->events->findVotable((new \DateTimeImmutable('today'))->modify('-60 days'));
        $ids = array_map(static fn (Event $e) => (int) $e->getId(), $events);
        $votes = $this->attendances->countsForEvents($ids);
        $checked = $this->checkIns->countsForEvents($ids);

        $now = new \DateTimeImmutable();
        $current = [];
        $past = [];
        foreach ($events as $e) {
            // Sans heure de fin, l'événement reste « en cours » jusqu'à la fin de sa journée.
            $end = $e->getEndsAt() ?? $e->getStartsAt()->setTime(23, 59, 59);
            $row = [
                'id' => $e->getId(),
                'title' => $e->getTitle(),
                'startsAt' => $e->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $e->getEndsAt()?->format(\DATE_ATOM),
                'isAllDay' => $e->isAllDay(),
                'location' => $e->getLocation(),
                'votes' => $votes[$e->getId()] ?? ['yes' => 0, 'maybe' => 0, 'no' => 0],
                'checkedCount' => $checked[$e->getId()] ?? 0,
            ];
            if ($end < $now) {
                $past[] = $row;
            } else {
                $current[] = $row;
            }
        }
        // findVotable trie par début croissant : les terminés sont à inverser.
        return new JsonResponse(['data' => array_merge($current, array_reverse($past))]);
    }

    /** Feuille d'émargement d'un événement : tous les adhérents, avec leur vote et leur état. */
    #[Route('/api/staff/check-in/events/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sheet(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);
        $event = $this->findEvent($id);

        $checkIns = $this->checkIns->findByEventIndexedByUser($event);

        /** @var array<int, array{user: User, vote: string}> $people */
        $people = [];
        foreach ($this->attendances->findByEventWithUser($event) as $a) {
            $people[$a->getUser()->getId()] = ['user' => $a->getUser(), 'vote' => $a->getStatus()->value];
        }
        foreach ($this->users->findActiveAdherentsForRecap() as $u) {
            $people[$u->getId()] ??= ['user' => $u, 'vote' => 'none'];
        }
        // Adhérent devenu inactif mais déjà émargé : il reste sur la feuille.
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
            ] + self::state($checkIns[$userId] ?? null);
        }
        usort($rows, static fn (array $a, array $b) => [self::VOTE_ORDER[$a['vote']], mb_strtolower($a['nom']), mb_strtolower($a['prenom'])]
            <=> [self::VOTE_ORDER[$b['vote']], mb_strtolower($b['nom']), mb_strtolower($b['prenom'])]);

        return new JsonResponse([
            'event' => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'startsAt' => $event->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $event->getEndsAt()?->format(\DATE_ATOM),
                'isAllDay' => $event->isAllDay(),
                'location' => $event->getLocation(),
            ],
            'checkedCount' => count($checkIns),
            'data' => $rows,
        ]);
    }

    /** Coche ou décoche la présence d'un adhérent. Body : { checked: bool }. Idempotent. */
    #[Route('/api/staff/check-in/events/{id}/members/{userId}', methods: ['PUT'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function toggle(int $id, int $userId, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);
        $event = $this->findEvent($id);
        $user = $this->users->find($userId);
        if ($user === null) {
            throw $this->createNotFoundException('Adhérent introuvable.');
        }

        $payload = json_decode($request->getContent(), true);
        $wanted = is_array($payload) && ($payload['checked'] ?? false) === true;

        $checkIn = $this->checkIns->findOneByEventAndUser($event, $user);
        if ($wanted && $checkIn === null) {
            $checkIn = new EventCheckIn($event, $user, $viewer);
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

    /** @return array{checked: bool, checkedAt: ?string, checkedBy: ?string} */
    private static function state(?EventCheckIn $c): array
    {
        return [
            'checked' => $c !== null,
            'checkedAt' => $c?->getCheckedAt()->format('H:i'),
            'checkedBy' => $c?->getCheckedBy()?->getFullName(),
        ];
    }
}
