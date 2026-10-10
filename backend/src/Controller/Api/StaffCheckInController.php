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

    /** Fenêtre de la liste mobile : jours avant le début d'un événement à venir, et après la fin d'un événement passé. */
    private const WINDOW_DAYS = 5;

    public function __construct(
        private readonly EventRepository $events,
        private readonly EventAttendanceRepository $attendances,
        private readonly EventCheckInRepository $checkIns,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Événements soumis au vote à émarger : ceux en cours ou à venir dont le début
     * tombe dans les 5 prochains jours (du plus proche au plus lointain), puis les
     * terminés depuis moins de 5 jours (du plus récent au plus ancien). Un événement
     * sur plusieurs jours reste proposé tant qu'il dure. Au-delà de cette fenêtre,
     * les événements restent consultables dans le backend.
     */
    #[Route('/api/staff/check-in/events', methods: ['GET'])]
    public function events(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);

        $now = new \DateTimeImmutable();
        $today = new \DateTimeImmutable('today');
        // Terminé depuis 5 jours au plus (dernier jour ≥ J-5) ; commence au plus tard le J+5 inclus.
        $since = $today->modify('-'.self::WINDOW_DAYS.' days');
        $until = $today->modify('+'.(self::WINDOW_DAYS + 1).' days');

        // findVotable trie par début croissant : l'ordre chronologique est conservé.
        $events = array_values(array_filter(
            $this->events->findVotable($since),
            static fn (Event $e) => $e->getStartsAt() < $until && self::lastDay($e) >= $since,
        ));
        $ids = array_map(static fn (Event $e) => (int) $e->getId(), $events);
        $votes = $this->attendances->countsForEvents($ids);
        $checked = $this->checkIns->countsForEvents($ids);

        $upcoming = [];
        $past = [];
        foreach ($events as $e) {
            // Sans heure de fin, l'événement reste « en cours » jusqu'à la fin de sa journée.
            $end = $e->getEndsAt() ?? $e->getStartsAt()->setTime(23, 59, 59);
            $row = self::eventPayload($e) + [
                'votes' => $votes[$e->getId()] ?? ['yes' => 0, 'maybe' => 0, 'no' => 0],
                'checkedCount' => $checked[$e->getId()] ?? 0,
            ];
            if ($end < $now) {
                $past[] = $row;
            } else {
                $upcoming[] = $row;
            }
        }

        // Les terminés arrivent du plus ancien au plus récent : on les inverse.
        return new JsonResponse(['data' => array_merge($upcoming, array_reverse($past))]);
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
            'event' => self::eventPayload($event),
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

    /**
     * Dernier jour de l'événement (à minuit) : le jour de fin, ou celui du début
     * sans fin. Une fin à minuit pile sur un événement horaire appartient à la
     * veille (22h → 00h = une seule soirée).
     */
    private static function lastDay(Event $e): \DateTimeImmutable
    {
        $start = $e->getStartsAt();
        $end = $e->getEndsAt();
        if ($end === null || $end <= $start) {
            return $start->setTime(0, 0);
        }
        if (!$e->isAllDay() && $end->format('H:i:s') === '00:00:00') {
            $end = $end->modify('-1 day');
        }

        return max($start->setTime(0, 0), $end->setTime(0, 0));
    }

    /** @return array<string, mixed> */
    private static function eventPayload(Event $e): array
    {
        return [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'startsAt' => $e->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $e->getEndsAt()?->format(\DATE_ATOM),
            // Dernier jour (AAAA-MM-JJ) : différent du jour de début pour un événement sur plusieurs jours.
            'lastDay' => self::lastDay($e)->format('Y-m-d'),
            'isAllDay' => $e->isAllDay(),
            'location' => $e->getLocation(),
        ];
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
