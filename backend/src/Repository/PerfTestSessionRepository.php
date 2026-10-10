<?php

namespace App\Repository;

use App\Entity\PerfTestSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerfTestSession>
 */
class PerfTestSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerfTestSession::class);
    }

    /**
     * Années (décroissantes) où au moins une séance a des temps saisis —
     * pour le sélecteur d'année de l'appli mobile.
     *
     * @return list<int>
     */
    public function findYearsWithResults(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.date AS d')
            ->innerJoin('s.results', 'r')
            ->orderBy('s.date', 'DESC')
            ->getQuery()
            ->getScalarResult();

        $years = [];
        foreach ($rows as $row) {
            $years[(int) substr((string) $row['d'], 0, 4)] = true;
        }
        return array_keys($years);
    }

    /**
     * Séances d'une année civile qui ont au moins un temps, avec leurs
     * temps et adhérents chargés en une requête, la plus récente d'abord.
     *
     * @return list<PerfTestSession>
     */
    public function findWithResultsForYear(int $year): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.results', 'r')->addSelect('r')
            ->innerJoin('r.user', 'u')->addSelect('u')
            ->where('s.date >= :from')->setParameter('from', sprintf('%04d-01-01', $year))
            ->andWhere('s.date <= :to')->setParameter('to', sprintf('%04d-12-31', $year))
            ->orderBy('s.date', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
