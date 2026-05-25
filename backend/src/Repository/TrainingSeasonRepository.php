<?php

namespace App\Repository;

use App\Entity\TrainingSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingSeason>
 */
class TrainingSeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSeason::class);
    }

    public function findCurrent(): ?TrainingSeason
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOrCreate(): TrainingSeason
    {
        $s = $this->findCurrent();
        if ($s !== null) {
            return $s;
        }
        $s = new TrainingSeason();
        $this->getEntityManager()->persist($s);
        $this->getEntityManager()->flush();
        return $s;
    }
}
