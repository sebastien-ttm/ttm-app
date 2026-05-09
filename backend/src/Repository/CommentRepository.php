<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Comment;
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
     * @return Paginator<Comment>
     */
    public function findByArticlePaginated(Article $article, int $page = 1, int $limit = 20): Paginator
    {
        $page = max(1, $page);
        $limit = min(50, max(1, $limit));

        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->where('c.article = :article')
            ->setParameter('article', $article)
            ->orderBy('c.createdAt', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb->getQuery());
    }
}
