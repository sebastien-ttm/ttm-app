<?php

namespace App\Repository;

use App\Entity\TrainingGroup;
use App\Entity\TrainingGroupComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingGroupComment>
 */
class TrainingGroupCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingGroupComment::class);
    }

    /**
     * Derniers commentaires du groupe, le plus récent d'abord (auteurs chargés).
     *
     * @return list<TrainingGroupComment>
     */
    public function findLatest(TrainingGroup $group, int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.author', 'a')
            ->where('c.trainingGroup = :g')
            ->setParameter('g', $group)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
