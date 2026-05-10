<?php

namespace App\Repository;

use App\Entity\ClubCharter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubCharter>
 */
class ClubCharterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubCharter::class);
    }

    public function findCurrent(): ?ClubCharter
    {
        return $this->createQueryBuilder('c')
            ->where('c.isActive = true')
            ->orderBy('c.publishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Sets all other charters' isActive to false.
     */
    public function deactivateAllExcept(?int $exceptId = null): void
    {
        $qb = $this->createQueryBuilder('c')
            ->update()
            ->set('c.isActive', ':false')
            ->setParameter('false', false);
        if ($exceptId !== null) {
            $qb->where('c.id != :id')->setParameter('id', $exceptId);
        }
        $qb->getQuery()->execute();
    }
}
