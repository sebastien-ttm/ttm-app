<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Audience\AudienceFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AudienceFilter $audienceFilter,
    ) {
        parent::__construct($registry, Event::class);
    }

    /**
     * @return list<Event>
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.startsAt >= :from AND e.startsAt <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startsAt', 'ASC');

        $this->audienceFilter->apply($qb, $viewer, 'e');

        return $qb->getQuery()->getResult();
    }
}
