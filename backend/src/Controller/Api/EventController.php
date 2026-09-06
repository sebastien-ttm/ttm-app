<?php

namespace App\Controller\Api;

use App\Entity\EventAttendance;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventRepository;
use App\Service\Serializer\ApiSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class EventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly ApiSerializer $serializer,
        private readonly EventAttendanceRepository $attendances,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/events/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $event = $this->events->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement introuvable.'], Response::HTTP_NOT_FOUND);
        }
        /** @var User $viewer */
        $viewer = $this->getUser();
        $counts = $event->isVoteEnabled() ? $this->attendances->countsForEvent($event) : null;
        $myVote = $event->isVoteEnabled()
            ? $this->attendances->findOneByUserAndEvent($viewer, $event)?->getStatus()->value
            : null;
        return new JsonResponse($this->serializer->event($event, $myVote, $counts));
    }

    /**
     * Enregistre / met à jour le vote de présence du user pour un
     * événement soumis au vote.
     * Body : { "status": "yes"|"no"|"maybe"|null }  (null = retire le vote)
     */
    #[Route('/api/events/{id}/attendance', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setAttendance(int $id, Request $request): JsonResponse
    {
        $event = $this->events->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (!$event->isVoteEnabled()) {
            return new JsonResponse(['error' => 'Cet événement n\'est pas soumis au vote.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        /** @var User $viewer */
        $viewer = $this->getUser();

        $payload = json_decode($request->getContent() ?: '{}', true);
        $raw = is_array($payload) ? ($payload['status'] ?? null) : null;
        $status = null;
        if ($raw !== null && $raw !== '') {
            $status = AttendanceStatus::tryFrom((string) $raw);
            if ($status === null) {
                return new JsonResponse(['error' => 'Statut invalide.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $existing = $this->attendances->findOneByUserAndEvent($viewer, $event);
        if ($status === null) {
            // Retire le vote (l'user hésite à nouveau) — supprime la ligne.
            if ($existing !== null) {
                $this->em->remove($existing);
                $this->em->flush();
            }
        } elseif ($existing !== null) {
            $existing->setStatus($status);
            $this->em->flush();
        } else {
            $this->em->persist(new EventAttendance($viewer, $event, $status));
            $this->em->flush();
        }

        return new JsonResponse([
            'ok' => true,
            'myVote' => $status?->value,
            'voteCounts' => $this->attendances->countsForEvent($event),
        ]);
    }

    #[Route('/api/events', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $fromStr = (string) $request->query->get('from', '');
        $toStr = (string) $request->query->get('to', '');

        try {
            $from = $fromStr !== '' ? new \DateTimeImmutable($fromStr) : new \DateTimeImmutable('-1 month');
            $to = $toStr !== '' ? new \DateTimeImmutable($toStr) : new \DateTimeImmutable('+6 months');
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Date invalide.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $viewer */
        $viewer = $this->getUser();

        $found = $this->events->findInRange($from, $to, $viewer);
        // Précalcul en 1 requête des votes du user + des compteurs pour
        // TOUS les events soumis au vote (évite N+1 sur la liste).
        $votedIds = array_values(array_map(fn ($e) => $e->getId(), array_filter($found, fn ($e) => $e->isVoteEnabled())));
        $myVotes = $this->attendances->votesForUserAndEvents($viewer, $votedIds);
        $counts = $this->attendances->countsForEvents($votedIds);

        return new JsonResponse([
            'data' => array_map(
                function ($e) use ($myVotes, $counts) {
                    $eid = $e->getId();
                    $myVote = isset($myVotes[$eid]) ? $myVotes[$eid]->value : null;
                    $c = $e->isVoteEnabled() ? ($counts[$eid] ?? ['yes' => 0, 'no' => 0, 'maybe' => 0]) : null;
                    return $this->serializer->event($e, $myVote, $c);
                },
                $found
            ),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }
}
