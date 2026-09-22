<?php

namespace App\Controller\Api;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Entity\MemberGroupMember;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\MemberGroup\MemberGroupService;
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
        private readonly AudienceFilter $audienceFilter,
        private readonly MemberGroupService $memberGroups,
    ) {
    }

    /**
     * Filet audience pour les endpoints unitaires (show / attendance) :
     * un deep-link direct ne doit pas contourner le filtrage appliqué
     * dans les listes. Retourne l'événement s'il est visible pour le
     * viewer, sinon 404 (on ne révèle pas l'existence de la ressource).
     */
    private function findVisibleOr404(int $id, ?User $viewer): Event
    {
        $event = $this->events->find($id);
        if ($event === null || !$this->audienceFilter->isVisible($event->getAudience(), $viewer)) {
            throw $this->createNotFoundException();
        }
        return $event;
    }

    #[Route('/api/events/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $event = $this->findVisibleOr404($id, $viewer);
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
        /** @var User $viewer */
        $viewer = $this->getUser();
        $event = $this->findVisibleOr404($id, $viewer);
        if (!$event->isVoteEnabled()) {
            return new JsonResponse(['error' => 'Cet événement n\'est pas soumis au vote.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

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
            }
        } elseif ($existing !== null) {
            $existing->setStatus($status);
        } else {
            $this->em->persist(new EventAttendance($viewer, $event, $status));
        }

        // Synchronise le groupe d'adhérents lié à l'événement :
        //  - 'yes' → l'user est ajouté au groupe (créé au besoin).
        //  - 'no' | 'maybe' | null (retrait) → l'user est retiré.
        // Le groupe est créé à la volée (source=event) — pas besoin
        // que l'admin le pré-crée. La cascade fait le reste.
        $group = $this->memberGroups->ensureGroupForEvent($event);
        if ($status === AttendanceStatus::Yes) {
            $this->memberGroups->addMember($group, $viewer, MemberGroupMember::SOURCE_EVENT_VOTE);
        } else {
            $this->memberGroups->removeMember($group, $viewer);
        }

        $this->em->flush();

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
