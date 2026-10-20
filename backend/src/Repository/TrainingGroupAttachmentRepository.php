<?php

namespace App\Repository;

use App\Entity\TrainingGroup;
use App\Entity\TrainingGroupAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingGroupAttachment>
 */
class TrainingGroupAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingGroupAttachment::class);
    }

    /** @return list<TrainingGroupAttachment> */
    public function findByGroup(TrainingGroup $group): array
    {
        return $this->findBy(['trainingGroup' => $group], ['uploadedAt' => 'ASC', 'id' => 'ASC']);
    }
}
