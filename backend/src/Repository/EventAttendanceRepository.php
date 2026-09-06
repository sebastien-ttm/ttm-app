<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventAttendance;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventAttendance>
 */
class EventAttendanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventAttendance::class);
    }

    public function findOneByUserAndEvent(User $user, Event $event): ?EventAttendance
    {
        return $this->findOneBy(['user' => $user, 'event' => $event]);
    }

    /**
     * Compteurs par statut pour un événement donné.
     *
     * @return array{yes:int, no:int, maybe:int}
     */
    public function countsForEvent(Event $event): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.status AS status, COUNT(a.id) AS n')
            ->where('a.event = :e')->setParameter('e', $event)
            ->groupBy('a.status')
            ->getQuery()->getResult();
        $out = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($rows as $r) {
            $key = $r['status'] instanceof AttendanceStatus ? $r['status']->value : (string) $r['status'];
            if (isset($out[$key])) {
                $out[$key] = (int) $r['n'];
            }
        }
        return $out;
    }

    /**
     * Compteurs pour une liste d'événements en 1 requête, indexés par
     * event.id. Évite le N+1 dans les vues « prochainement ».
     *
     * @param list<int> $eventIds
     * @return array<int, array{yes:int, no:int, maybe:int}>
     */
    public function countsForEvents(array $eventIds): array
    {
        if ($eventIds === []) return [];
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.event) AS eid, a.status AS status, COUNT(a.id) AS n')
            ->where('a.event IN (:ids)')->setParameter('ids', $eventIds)
            ->groupBy('a.event')->addGroupBy('a.status')
            ->getQuery()->getResult();
        $out = [];
        foreach ($eventIds as $id) {
            $out[$id] = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        }
        foreach ($rows as $r) {
            $eid = (int) $r['eid'];
            $key = $r['status'] instanceof AttendanceStatus ? $r['status']->value : (string) $r['status'];
            if (isset($out[$eid][$key])) {
                $out[$eid][$key] = (int) $r['n'];
            }
        }
        return $out;
    }

    /**
     * Votes d'un user pour une liste d'événements, indexés par event.id.
     * Un event non voté n'apparait pas dans le tableau retourné.
     *
     * @param list<int> $eventIds
     * @return array<int, AttendanceStatus>
     */
    public function votesForUserAndEvents(User $user, array $eventIds): array
    {
        if ($eventIds === []) return [];
        /** @var list<EventAttendance> $rows */
        $rows = $this->createQueryBuilder('a')
            ->where('a.user = :u')->setParameter('u', $user)
            ->andWhere('a.event IN (:ids)')->setParameter('ids', $eventIds)
            ->getQuery()->getResult();
        $out = [];
        foreach ($rows as $a) {
            $out[$a->getEvent()->getId()] = $a->getStatus();
        }
        return $out;
    }
}
