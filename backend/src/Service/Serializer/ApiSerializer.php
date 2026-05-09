<?php

namespace App\Service\Serializer;

use App\Entity\Article;
use App\Entity\ArticlePhoto;
use App\Entity\Banner;
use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\MenuItem;
use App\Entity\StaticPage;
use App\Entity\TrainingPlan;
use App\Entity\User;

/**
 * Lightweight, explicit JSON serialization for API responses.
 * Avoids the complexity of Symfony's Serializer groups for read-only payloads.
 */
class ApiSerializer
{
    public function __construct(private readonly string $publicUrl)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function user(User $u): array
    {
        return [
            'id' => $u->getId(),
            'fullName' => $u->getFullName(),
            'prenom' => $u->getPrenom(),
            'nom' => $u->getNom(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function article(Article $a, ?User $viewer = null): array
    {
        $myReactions = [];
        if ($viewer !== null) {
            foreach ($a->getReactions() as $r) {
                if ($r->getUser()->getId() === $viewer->getId()) {
                    $myReactions[] = $r->getEmoji();
                }
            }
        }

        return [
            'id' => $a->getId(),
            'title' => $a->getTitle(),
            'content' => $a->getContent(),
            'publishedAt' => $a->getPublishedAt()?->format(\DATE_ATOM),
            'author' => $this->user($a->getAuthor()),
            'photos' => array_map(fn (ArticlePhoto $p) => $this->photo($p), $a->getPhotos()->toArray()),
            'reactionCounts' => $a->getReactionCounts(),
            'myReactions' => $myReactions,
            'commentCount' => $a->getComments()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function photo(ArticlePhoto $p): array
    {
        return [
            'id' => $p->getId(),
            'url' => $p->getFilePath() !== null
                ? rtrim($this->publicUrl, '/').'/uploads/articles/'.$p->getFilePath()
                : null,
            'alt' => $p->getAlt(),
            'position' => $p->getPosition(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(Comment $c): array
    {
        return [
            'id' => $c->getId(),
            'content' => $c->getContent(),
            'createdAt' => $c->getCreatedAt()->format(\DATE_ATOM),
            'user' => $this->user($c->getUser()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function trainingPlan(TrainingPlan $t): array
    {
        return [
            'id' => $t->getId(),
            'title' => $t->getTitle(),
            'description' => $t->getDescription(),
            'fileUrl' => rtrim($this->publicUrl, '/').'/api/training-plans/'.$t->getId().'/file',
            'postedBy' => $this->user($t->getPostedBy()),
            'weekStartsAt' => $t->getWeekStartsAt()?->format('Y-m-d'),
            'postedAt' => $t->getPostedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function staticPage(StaticPage $p): array
    {
        return [
            'slug' => $p->getSlug(),
            'title' => $p->getTitle(),
            'content' => $p->getContent(),
            'updatedAt' => $p->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function menuItem(MenuItem $m): array
    {
        return [
            'id' => $m->getId(),
            'label' => $m->getLabel(),
            'type' => $m->getType()->value,
            'target' => $m->getTarget(),
            'icon' => $m->getIcon(),
            'position' => $m->getPosition(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function event(Event $e): array
    {
        return [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $e->getDescription(),
            'location' => $e->getLocation(),
            'startsAt' => $e->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $e->getEndsAt()?->format(\DATE_ATOM),
            'type' => $e->getType()->value,
            'color' => $e->getColor(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function banner(Banner $b): array
    {
        return [
            'id' => $b->getId(),
            'imageUrl' => $b->getImagePath() !== null
                ? rtrim($this->publicUrl, '/').'/uploads/banners/'.$b->getImagePath()
                : null,
            'title' => $b->getTitle(),
            'linkUrl' => $b->getLinkUrl(),
        ];
    }
}
