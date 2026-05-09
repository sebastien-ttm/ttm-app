<?php

namespace App\Repository;

use App\Entity\StaticPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaticPage>
 */
class StaticPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaticPage::class);
    }

    public function findOneBySlugPublished(string $slug): ?StaticPage
    {
        return $this->findOneBy(['slug' => $slug, 'isPublished' => true]);
    }

    /**
     * @return list<StaticPage>
     */
    public function findAllPublished(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isPublished = true')
            ->orderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
