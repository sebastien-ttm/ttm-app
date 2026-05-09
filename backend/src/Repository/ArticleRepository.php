<?php

namespace App\Repository;

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * @return Paginator<Article>
     */
    public function findPublishedPaginated(int $page = 1, int $limit = 20): Paginator
    {
        $page = max(1, $page);
        $limit = min(50, max(1, $limit));

        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.author', 'author')->addSelect('author')
            ->leftJoin('a.photos', 'photos')->addSelect('photos')
            ->where('a.publishedAt IS NOT NULL AND a.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('a.publishedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb->getQuery(), fetchJoinCollection: true);
    }
}
