<?php

namespace App\Repository;

use App\Entity\StaffDayUnavailability;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffDayUnavailability>
 */
class StaffDayUnavailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffDayUnavailability::class);
    }

    public function findOneByUserAndDate(User $user, \DateTimeImmutable $date): ?StaffDayUnavailability
    {
        return $this->findOneBy(['user' => $user, 'date' => $date->setTime(0, 0, 0)]);
    }

    /**
     * @return list<StaffDayUnavailability>
     */
    public function findByUserAndWeek(User $user, \DateTimeImmutable $weekStartsAt): array
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $sunday = $monday->modify('+6 days');
        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->setParameter('user', $user)
            ->setParameter('from', $monday->format('Y-m-d'))
            ->setParameter('to', $sunday->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<StaffDayUnavailability>
     */
    public function findForWeek(\DateTimeImmutable $weekStartsAt): array
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $sunday = $monday->modify('+6 days');
        return $this->createQueryBuilder('d')
            ->leftJoin('d.user', 'u')->addSelect('u')
            ->where('d.date BETWEEN :from AND :to')
            ->setParameter('from', $monday->format('Y-m-d'))
            ->setParameter('to', $sunday->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }
}
