<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventCheckIn;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventCheckIn>
 */
class EventCheckInRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventCheckIn::class);
    }

    public function findOneByEventAndUser(Event $event, User $user): ?EventCheckIn
    {
        return $this->findOneBy(['event' => $event, 'user' => $user]);
    }

    /**
     * Émargements d'un événement, indexés par id d'adhérent.
     *
     * @return array<int, EventCheckIn>
     */
    public function findByEventIndexedByUser(Event $event): array
    {
        $rows = $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->leftJoin('c.checkedBy', 'b')->addSelect('b')
            ->where('c.event = :e')->setParameter('e', $event)
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->getUser()->getId()] = $row;
        }
        return $out;
    }

    /**
     * Nombre d'émargés par événement, en une requête.
     *
     * @param list<int> $eventIds
     * @return array<int, int> id événement => nombre
     */
    public function countsForEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.event) AS eid, COUNT(c.id) AS n')
            ->where('c.event IN (:ids)')->setParameter('ids', $eventIds)
            ->groupBy('c.event')
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['eid']] = (int) $r['n'];
        }
        return $out;
    }
}
