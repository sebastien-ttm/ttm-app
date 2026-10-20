<?php

namespace App\Repository;

use App\Entity\TrainingGroup;
use App\Entity\TrainingGroupPosition;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingGroupPosition>
 */
class TrainingGroupPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingGroupPosition::class);
    }

    public function findOneFor(TrainingGroup $group, User $user): ?TrainingGroupPosition
    {
        return $this->findOneBy(['trainingGroup' => $group, 'user' => $user]);
    }

    /**
     * Positions mises à jour depuis `$since` (une position plus ancienne est celle d'un partage abandonné), utilisateurs
     * chargés, dans un ordre stable.
     *
     * @return list<TrainingGroupPosition>
     */
    public function findFresh(TrainingGroup $group, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('u')
            ->join('p.user', 'u')
            ->where('p.trainingGroup = :g')
            ->andWhere('p.updatedAt >= :since')
            ->setParameter('g', $group)
            ->setParameter('since', $since)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
