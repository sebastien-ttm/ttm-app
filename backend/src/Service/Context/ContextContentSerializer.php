<?php

namespace App\Service\Context;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\ArticleReadRepository;
use App\Repository\EventAttendanceRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\AvatarService;
use App\Service\Serializer\ApiSerializer;
use Psr\Log\LoggerInterface;

/**
 * Sérialisation des contenus d'une « vue de contexte » (déplacement, stage) : événements au format du calendrier
 * (vote du viewer + compteurs), articles au format du fil (avec leur état « lu ») et participants (identité et
 * photo, jamais de coordonnées).
 */
class ContextContentSerializer
{
    public function __construct(
        private readonly ApiSerializer $serializer,
        private readonly AudienceFilter $audienceFilter,
        private readonly EventAttendanceRepository $attendances,
        private readonly ArticleReadRepository $reads,
        private readonly AvatarService $avatars,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Événements au format de GET /api/events, ce qui permet de réutiliser leur rendu côté appli.
     * `$applyAudience` : appliquer les filtres d'audience du calendrier (événements existants rattachés à un
     * déplacement) ; un événement dédié à un stage n'en a pas besoin (ses participants sont son audience).
     *
     * @param iterable<Event> $events
     *
     * @return list<array<string, mixed>>
     */
    public function events(iterable $events, User $viewer, bool $applyAudience): array
    {
        $visible = [];
        foreach ($events as $e) {
            if ($applyAudience
                && !($this->audienceFilter->isVisible($e->getAudience(), $viewer)
                    && $this->audienceFilter->isContentVisibleForDirigeant($e->getContentAudience(), $viewer))) {
                continue;
            }
            $visible[] = $e;
        }
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

    /**
     * Articles publiés, au format du fil, avec leur état « lu » (information annexe, jamais bloquante).
     *
     * @param iterable<Article> $articles
     *
     * @return list<array<string, mixed>>
     */
    public function articles(iterable $articles, User $viewer): array
    {
        $published = [];
        foreach ($articles as $a) {
            if ($a->isPublished()) {
                $published[] = $a;
            }
        }

        try {
            $readIds = $this->reads->readIdsForUser($viewer, array_map(static fn (Article $a) => (int) $a->getId(), $published));
        } catch (\Throwable $e) {
            $this->logger->warning('Suivi de lecture des articles indisponible', ['error' => $e->getMessage()]);
            $readIds = [];
        }

        return array_map(
            fn (Article $a) => $this->serializer->article($a, $viewer) + ['read' => isset($readIds[$a->getId()])],
            $published,
        );
    }

    /**
     * Participants par ordre alphabétique : identité et photo seulement, les participants se reconnaissent entre eux
     * sans coordonnées.
     *
     * @param iterable<User> $users
     *
     * @return list<array<string, mixed>>
     */
    public function participants(iterable $users): array
    {
        $list = [];
        foreach ($users as $u) {
            $list[] = $u;
        }
        usort($list, static fn (User $a, User $b) => [mb_strtolower($a->getNom()), mb_strtolower($a->getPrenom())]
            <=> [mb_strtolower($b->getNom()), mb_strtolower($b->getPrenom())]);

        return array_map(fn (User $u) => [
            'id' => $u->getId(),
            'fullName' => $u->getFullName(),
            'prenom' => $u->getPrenom(),
            'nom' => $u->getNom(),
            'avatarUrl' => $this->avatars->urlFor($u),
        ], $list);
    }
}
