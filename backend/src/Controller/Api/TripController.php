<?php

namespace App\Controller\Api;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\ArticleReadRepository;
use App\Repository\EventAttendanceRepository;
use App\Repository\TripRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\AvatarService;
use App\Service\Serializer\ApiSerializer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Déplacements du club (créés depuis le backend) : chaque participant retrouve dans l'appli une vue dédiée avec
 * les informations pratiques, les événements du calendrier rattachés et les articles réservés au déplacement.
 * Un déplacement n'est jamais visible d'un adhérent qui n'y participe pas (404), ni tant qu'il est en brouillon.
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/trips')]
class TripController extends AbstractController
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly ApiSerializer $serializer,
        private readonly AudienceFilter $audienceFilter,
        private readonly EventAttendanceRepository $attendances,
        private readonly ArticleReadRepository $reads,
        private readonly AvatarService $avatars,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Mes déplacements : à venir, en cours ou terminés depuis peu (de quoi alimenter l'accueil). */
    #[Route('/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        return new JsonResponse([
            'data' => array_map(fn (Trip $t) => $this->summary($t), $this->trips->findCurrentForParticipant($viewer)),
        ]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(Trip $trip): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$trip->isPublished() || !$trip->hasParticipant($viewer)) {
            throw $this->createNotFoundException();
        }

        $articles = array_values(array_filter(
            $trip->getArticles()->toArray(),
            static fn (Article $a) => $a->isPublished(),
        ));
        // Articles déjà ouverts : l'appli les présente autrement. Information annexe, jamais bloquante.
        try {
            $readIds = $this->reads->readIdsForUser($viewer, array_map(static fn (Article $a) => (int) $a->getId(), $articles));
        } catch (\Throwable $e) {
            $this->logger->warning('Suivi de lecture des articles indisponible', ['error' => $e->getMessage()]);
            $readIds = [];
        }

        $participants = $trip->getParticipants()->toArray();
        usort($participants, static fn (User $a, User $b) => [mb_strtolower($a->getNom()), mb_strtolower($a->getPrenom())]
            <=> [mb_strtolower($b->getNom()), mb_strtolower($b->getPrenom())]);

        return new JsonResponse($this->summary($trip) + [
            'description' => $trip->getDescription(),
            'events' => $this->serializeEvents($trip, $viewer),
            'articles' => array_map(
                fn (Article $a) => $this->serializer->article($a, $viewer) + ['read' => isset($readIds[$a->getId()])],
                $articles,
            ),
            // Identité et photo seulement : les participants se reconnaissent entre eux, sans coordonnées.
            'participants' => array_map(fn (User $u) => [
                'id' => $u->getId(),
                'fullName' => $u->getFullName(),
                'prenom' => $u->getPrenom(),
                'nom' => $u->getNom(),
                'avatarUrl' => $this->avatars->urlFor($u),
            ], $participants),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Trip $trip): array
    {
        return [
            'id' => $trip->getId(),
            'title' => $trip->getTitle(),
            'icon' => $trip->getIcon(),
            'location' => $trip->getLocation(),
            'startsOn' => $trip->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $trip->getEndsOn()?->format('Y-m-d'),
            'participantCount' => $trip->getParticipants()->count(),
        ];
    }

    /**
     * Événements rattachés, sérialisés comme dans GET /api/events (vote du viewer + compteurs) pour réutiliser leur
     * rendu côté appli. Mêmes filtres d'audience que le calendrier : un événement invisible pour le viewer est omis.
     *
     * @return list<array<string, mixed>>
     */
    private function serializeEvents(Trip $trip, User $viewer): array
    {
        $visible = array_values(array_filter(
            $trip->getEvents()->toArray(),
            fn (Event $e) => $this->audienceFilter->isVisible($e->getAudience(), $viewer)
                && $this->audienceFilter->isContentVisibleForDirigeant($e->getContentAudience(), $viewer),
        ));
        if ($visible === []) {
            return [];
        }

        $votedIds = array_values(array_map(
            fn (Event $e) => $e->getId(),
            array_filter($visible, fn (Event $e) => $e->isVoteEnabled()),
        ));
        $myVotes = $this->attendances->votesForUserAndEvents($viewer, $votedIds);
        $counts = $this->attendances->countsForEvents($votedIds);

        return array_map(function (Event $e) use ($myVotes, $counts) {
            $eid = $e->getId();
            $myVote = isset($myVotes[$eid]) ? $myVotes[$eid]->value : null;
            $c = $e->isVoteEnabled() ? ($counts[$eid] ?? ['yes' => 0, 'no' => 0, 'maybe' => 0]) : null;

            return $this->serializer->event($e, $myVote, $c);
        }, $visible);
    }
}
