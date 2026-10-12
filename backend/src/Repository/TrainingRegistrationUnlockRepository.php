<?php

namespace App\Repository;

use App\Entity\TrainingRegistrationUnlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingRegistrationUnlock>
 */
class TrainingRegistrationUnlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingRegistrationUnlock::class);
    }

    public function findOneByWeek(\DateTimeImmutable $monday): ?TrainingRegistrationUnlock
    {
        return $this->findOneBy(['weekStartsAt' => $monday->modify('monday this week')->setTime(0, 0, 0)]);
    }

    public function isUnlocked(\DateTimeImmutable $monday): bool
    {
        return $this->findOneByWeek($monday) !== null;
    }
}
