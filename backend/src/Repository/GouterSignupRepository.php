<?php

namespace App\Repository;

use App\Entity\GouterSignup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GouterSignup>
 */
class GouterSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GouterSignup::class);
    }

    /**
     * Positionnements dans un intervalle de dates (inclus).
     *
     * @return list<GouterSignup>
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.user', 'u')->addSelect('u')
            ->where('g.date BETWEEN :from AND :to')
            ->setParameter('from', $from->setTime(0, 0, 0)->format('Y-m-d'))
            ->setParameter('to', $to->setTime(0, 0, 0)->format('Y-m-d'))
            ->orderBy('g.date', 'ASC')
            ->addOrderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByDateUser(\DateTimeImmutable $date, User $user): ?GouterSignup
    {
        return $this->createQueryBuilder('g')
            ->where('g.date = :d')->setParameter('d', $date->setTime(0, 0, 0)->format('Y-m-d'))
            ->andWhere('g.user = :u')->setParameter('u', $user)
            ->getQuery()->getOneOrNullResult();
    }

    public function countForDate(\DateTimeImmutable $date): int
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->where('g.date = :d')->setParameter('d', $date->setTime(0, 0, 0)->format('Y-m-d'))
            ->getQuery()->getSingleScalarResult();
    }
}
