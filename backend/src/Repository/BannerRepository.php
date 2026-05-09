<?php

namespace App\Repository;

use App\Entity\Banner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Banner>
 */
class BannerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Banner::class);
    }

    public function findCurrentActive(\DateTimeImmutable $now = null): ?Banner
    {
        $now = $now ?? new \DateTimeImmutable();
        return $this->createQueryBuilder('b')
            ->where('b.isActive = true')
            ->andWhere('b.startsAt IS NULL OR b.startsAt <= :now')
            ->andWhere('b.endsAt IS NULL OR b.endsAt >= :now')
            ->setParameter('now', $now)
            ->orderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
