<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Kept for backwards compat with the old paginated-only endpoint —
     * ne renvoie QUE les commentaires top-level (parent IS NULL) pour
     * ne pas mélanger racines et réponses dans une même page.
     *
     * @return Paginator<Comment>
     */
    public function findByArticlePaginated(Article $article, int $page = 1, int $limit = 20): Paginator
    {
        $page = max(1, $page);
        $limit = min(50, max(1, $limit));

        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->where('c.article = :article')
            ->andWhere('c.parent IS NULL')
            ->setParameter('article', $article)
            ->orderBy('c.createdAt', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb->getQuery());
    }

    /**
     * Tous les commentaires (top-level + réponses) d'un article dans
     * l'ordre chronologique. Utilisé par la nouvelle vue threadée
     * mobile qui reconstruit l'arbre côté client à partir de parentId.
     *
     * @return list<Comment>
     */
    public function findAllByArticle(Article $article): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->where('c.article = :article')
            ->setParameter('article', $article)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Comment>
     */
    public function findAllByEvent(Event $event): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->where('c.event = :event')
            ->setParameter('event', $event)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
