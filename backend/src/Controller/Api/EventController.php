<?php

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Repository\CommentRepository;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\AvatarService;
use App\Service\Event\EventAttendanceService;
use App\Service\Serializer\ApiSerializer;
use App\Service\Training\TrainingRegistrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
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
        private readonly EventAttendanceService $attendanceService,
        private readonly CommentRepository $comments,
        private readonly ValidatorInterface $validator,
        private readonly TrainingRegistrationService $trainingRegistrations,
        private readonly AvatarService $avatars,
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
        $data = $this->serializer->event($event, $myVote, $counts);
        // Détail uniquement : créneaux d'entraînement occasionnels liés, au format de la liste de la semaine (avec leur
        // inscription éventuelle : l'appli ouvre directement leur écran détail).
        $data['trainingSlots'] = $this->trainingRegistrations->linkedSlotsForEvent($event, $viewer);
        // Anciennes versions de l'appli : le premier créneau lié, comme avant.
        $data['trainingSlot'] = $data['trainingSlots'][0] ?? null;
        return new JsonResponse($data);
    }

    /**
     * Qui vient ? Les adhérents ayant voté « présent », « peut-être » et « absent » à un événement
     * soumis au vote, par ordre alphabétique. Visible par tous ceux qui voient l'événement (voteurs
     * ou non) ; seuls le nom, le prénom et la photo sont exposés. Comptes désactivés exclus.
     *
     * Réponse : { yes: [...], maybe: [...], no: [...] } — chaque entrée { id, prenom, nom, avatarUrl }.
     */
    #[Route('/api/events/{id}/attendees', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function attendees(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $event = $this->findVisibleOr404($id, $viewer);

        $groups = ['yes' => [], 'maybe' => [], 'no' => []];
        if ($event->isVoteEnabled()) {
            foreach ($this->attendances->findByEventWithUser($event) as $attendance) {
                $user = $attendance->getUser();
                if (!$user->isActive()) {
                    continue;
                }
                $groups[$attendance->getStatus()->value][] = [
                    'id' => $user->getId(),
                    'prenom' => $user->getPrenom(),
                    'nom' => $user->getNom(),
                    'avatarUrl' => $this->avatars->urlFor($user),
                ];
            }
        }

        return new JsonResponse($groups);
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

        // Une fois l'événement commencé, le vote est figé (seul l'admin peut le surcharger depuis le backend).
        if ($event->isVoteLocked()) {
            return new JsonResponse(
                ['error' => 'Les votes sont clos : l\'événement a commencé.', 'locked' => true],
                Response::HTTP_FORBIDDEN,
            );
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

        // Enregistre le vote et synchronise le groupe d'adhérents lié à l'événement.
        $this->attendanceService->setVote($event, $viewer, $status);

        return new JsonResponse([
            'ok' => true,
            'myVote' => $status?->value,
            // Vote retiré : plus de participation, donc plus de résultats.
            'voteCounts' => $status !== null ? $this->attendances->countsForEvent($event) : null,
        ]);
    }

    /**
     * Liste des commentaires (racines + réponses threadées) d'un
     * événement. Le client reconstruit l'arbre via parentId.
     */
    #[Route('/api/events/{id}/comments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listEventComments(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $event = $this->findVisibleOr404($id, $viewer);
        $all = $this->comments->findAllByEvent($event);
        return new JsonResponse([
            'data' => array_map(fn (Comment $c) => $this->serializer->comment($c), $all),
            'total' => count($all),
        ]);
    }

    /**
     * Ajoute un commentaire (top-level si parentId absent, réponse
     * threadée sinon). Comme pour les articles, les réponses sont
     * ouvertes à tous les adhérents — la modération se fait a
     * posteriori si nécessaire.
     */
    #[Route('/api/events/{id}/comments', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addEventComment(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $event = $this->findVisibleOr404($id, $user);
        $payload = json_decode($request->getContent(), true);
        $content = is_array($payload) ? trim((string) ($payload['content'] ?? '')) : '';
        $parentId = is_array($payload) && isset($payload['parentId']) && $payload['parentId'] !== ''
            ? (int) $payload['parentId']
            : null;

        if ($content === '' || mb_strlen($content) > 2000) {
            return new JsonResponse(['error' => 'Le commentaire doit faire entre 1 et 2000 caractères.'], Response::HTTP_BAD_REQUEST);
        }

        if ($parentId !== null) {
            $parent = $this->comments->find($parentId);
            if ($parent === null || $parent->getEvent()?->getId() !== $event->getId()) {
                return new JsonResponse(['error' => 'Commentaire parent introuvable.'], Response::HTTP_NOT_FOUND);
            }
            $comment = Comment::reply($parent, $user, $content);
        } else {
            $comment = Comment::forEvent($event, $user, $content);
        }

        $errors = $this->validator->validate($comment);
        if (count($errors) > 0) {
            return new JsonResponse(['error' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($comment);
        $this->em->flush();

        return new JsonResponse($this->serializer->comment($comment), Response::HTTP_CREATED);
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
        // L'appli envoie des instants UTC (« …T22:00:00.000Z » pour minuit à Paris) alors que
        // les dates des événements sont stockées à l'heure de Paris : Doctrine écrit la date
        // SANS convertir le fuseau. Sans ce recalage la fenêtre démarrait 1 à 2 h trop tôt et
        // les événements de la veille au soir apparaissaient dans « Prochainement ».
        $appTimezone = new \DateTimeZone(date_default_timezone_get());
        $from = $from->setTimezone($appTimezone);
        $to = $to->setTimezone($appTimezone);

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
