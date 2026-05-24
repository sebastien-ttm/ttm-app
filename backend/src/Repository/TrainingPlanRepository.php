<?php

namespace App\Repository;

use App\Entity\TrainingPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingPlan>
 */
class TrainingPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingPlan::class);
    }

    /**
     * @return Paginator<TrainingPlan>
     */
    public function findPaginated(int $page = 1, int $limit = 20): Paginator
    {
        $page = max(1, $page);
        $limit = min(50, max(1, $limit));

        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.postedBy', 'p')->addSelect('p')
            ->orderBy('t.postedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb->getQuery());
    }

    /**
     * Plans dont le weekStartsAt correspond exactement à la semaine demandée.
     *
     * @return list<TrainingPlan>
     */
    public function findForWeek(\DateTimeImmutable $weekStartsAt): array
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);

        return $this->createQueryBuilder('t')
            ->leftJoin('t.postedBy', 'p')->addSelect('p')
            ->where('t.weekStartsAt = :w')
            ->setParameter('w', $monday->format('Y-m-d'))
            ->orderBy('t.category', 'ASC')
            ->addOrderBy('t.postedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
