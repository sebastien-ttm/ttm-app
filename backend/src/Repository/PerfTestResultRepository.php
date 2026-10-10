<?php

namespace App\Repository;

use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerfTestResult>
 */
class PerfTestResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerfTestResult::class);
    }

    public function findOneBySessionAndUser(PerfTestSession $session, User $user): ?PerfTestResult
    {
        return $this->findOneBy(['session' => $session, 'user' => $user]);
    }

    /**
     * Tous les temps d'un adhérent, toutes saisons, du plus ancien au plus
     * récent, avec leur séance (« Mon évolution » dans l'appli).
     *
     * @return list<PerfTestResult>
     */
    public function findByUserWithSession(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.session', 's')->addSelect('s')
            ->where('r.user = :u')->setParameter('u', $user)
            ->orderBy('s.date', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Temps (en secondes) de TOUS les participants des séances données,
     * par id de séance — pour calculer rang et nombre de participants.
     *
     * @param list<int> $sessionIds
     * @return array<int, list<int>>
     */
    public function findTimesBySessionIds(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.session) AS sid', 'r.timeSeconds AS t')
            ->where('r.session IN (:ids)')->setParameter('ids', $sessionIds)
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['sid']][] = (int) $row['t'];
        }
        return $out;
    }

    /**
     * Nombre de temps saisis (adhérents et anciens adhérents) par séance.
     *
     * @param list<int> $sessionIds
     * @return array<int, int> id de séance => nombre de temps
     */
    public function countBySessionIds(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.session) AS sid', 'COUNT(r.id) AS n')
            ->where('r.session IN (:ids)')->setParameter('ids', $sessionIds)
            ->groupBy('r.session')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['sid']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Temps d'une séance, indexés par id d'adhérent.
     *
     * @return array<int, PerfTestResult>
     */
    public function findBySessionIndexedByUser(PerfTestSession $session): array
    {
        $rows = $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')->addSelect('u')
            ->leftJoin('r.enteredBy', 'b')->addSelect('b')
            ->where('r.session = :s')->setParameter('s', $session)
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $row) {
            // Les anciens adhérents (sans compte) n'ont pas d'id : voir findLegacyBySession().
            if ($row->getUser() !== null) {
                $out[$row->getUser()->getId()] = $row;
            }
        }
        return $out;
    }

    /**
     * Temps des anciens adhérents (sans compte) d'une séance, du plus rapide au plus lent.
     *
     * @return list<PerfTestResult>
     */
    public function findLegacyBySession(PerfTestSession $session): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.session = :s')->setParameter('s', $session)
            ->andWhere('r.user IS NULL')
            ->orderBy('r.timeSeconds', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * TOUS les temps d'une séance (adhérents et anciens adhérents), du plus rapide au plus lent.
     *
     * @return list<PerfTestResult>
     */
    public function findAllBySession(PerfTestSession $session): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')->addSelect('u')
            ->where('r.session = :s')->setParameter('s', $session)
            ->orderBy('r.timeSeconds', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Historique antérieur à une séance, sur la même épreuve (et la même
     * longueur de bassin en natation), pour afficher la progression :
     * dernier temps et meilleur temps de chaque adhérent.
     *
     * @return array<int, array{last: PerfTestResult, best: PerfTestResult}> id user => résultats
     */
    public function findHistoryBefore(PerfTestSession $session): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.session', 's')->addSelect('s')
            ->where('s.test = :test')->setParameter('test', $session->getTest())
            ->andWhere('s.date < :date OR (s.date = :date AND s.id < :id)')
            ->setParameter('date', $session->getDate()->format('Y-m-d'))
            ->setParameter('id', $session->getId())
            ->orderBy('s.date', 'DESC')
            ->addOrderBy('s.id', 'DESC');
        if ($session->getPoolLength() !== null) {
            $qb->andWhere('s.poolLength = :pool')->setParameter('pool', $session->getPoolLength());
        }

        $out = [];
        foreach ($qb->getQuery()->getResult() as $r) {
            if ($r->getUser() === null) {
                continue; // ancien adhérent sans compte : pas d'historique individuel
            }
            $uid = $r->getUser()->getId();
            if (!isset($out[$uid])) {
                $out[$uid] = ['last' => $r, 'best' => $r];
            } elseif ($r->getTimeSeconds() < $out[$uid]['best']->getTimeSeconds()) {
                $out[$uid]['best'] = $r;
            }
        }
        return $out;
    }
}
