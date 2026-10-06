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
            $out[$row->getUser()->getId()] = $row;
        }
        return $out;
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
