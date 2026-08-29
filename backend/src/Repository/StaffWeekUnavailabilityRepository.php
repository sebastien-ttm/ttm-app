<?php

namespace App\Repository;

use App\Entity\StaffWeekUnavailability;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffWeekUnavailability>
 */
class StaffWeekUnavailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffWeekUnavailability::class);
    }

    public function findOneByUserAndWeek(User $user, \DateTimeImmutable $weekStartsAt): ?StaffWeekUnavailability
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        return $this->findOneBy(['user' => $user, 'weekStartsAt' => $monday]);
    }

    /**
     * @return list<StaffWeekUnavailability>
     */
    public function findForWeek(\DateTimeImmutable $weekStartsAt): array
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        return $this->createQueryBuilder('u')
            ->leftJoin('u.user', 'usr')->addSelect('usr')
            ->where('u.weekStartsAt = :w')
            ->setParameter('w', $monday->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }
}
