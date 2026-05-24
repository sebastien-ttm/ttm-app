<?php

namespace App\Repository;

use App\Entity\TrainingSlotTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingSlotTemplate>
 */
class TrainingSlotTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSlotTemplate::class);
    }

    /**
     * @return list<TrainingSlotTemplate>
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = true')
            ->orderBy('t.dayOfWeek', 'ASC')
            ->addOrderBy('t.startTime', 'ASC')
            ->addOrderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
