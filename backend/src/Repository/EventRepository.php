<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @return list<Event>
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.startsAt >= :from AND e.startsAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
