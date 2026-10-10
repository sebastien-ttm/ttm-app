<?php

namespace App\Repository;

use App\Entity\PerfTestSession;
use App\Enum\PerfTest;
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
     * Saison d'entraînement d'une date, identifiée par son année de
     * début : du 1er septembre au 31 août (le 12 mars 2026 → 2025, le
     * 15 octobre 2026 → 2026).
     */
    public static function seasonStartYear(\DateTimeInterface $date): int
    {
        $year = (int) $date->format('Y');
        return (int) $date->format('n') >= 9 ? $year : $year - 1;
    }

    /** « 2025-2026 » pour la saison qui démarre en 2025. */
    public static function seasonLabel(int $startYear): string
    {
        return $startYear.'-'.($startYear + 1);
    }

    /**
     * Prises de temps d'une épreuve (et d'un bassin en natation), la plus
     * récente d'abord — pour choisir où rattacher un temps déclaré.
     *
     * @return list<PerfTestSession>
     */
    public function findRecentForTest(PerfTest $test, ?int $poolLength, int $limit = 15): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.test = :test')->setParameter('test', $test)
            ->orderBy('s.date', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults($limit);
        if ($poolLength === null) {
            $qb->andWhere('s.poolLength IS NULL');
        } else {
            $qb->andWhere('s.poolLength = :pool')->setParameter('pool', $poolLength);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Prises de temps en cours, à venir, ou terminées depuis la date donnée
     * (fin de période), la plus récente d'abord — pour la saisie des temps
     * dans l'appli.
     *
     * @return list<PerfTestSession>
     */
    public function findOngoingOrRecent(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('s')
            ->where('COALESCE(s.endDate, s.date) >= :since')->setParameter('since', $since->format('Y-m-d'))
            ->orderBy('s.date', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** La prise de temps la plus récente d'une épreuve (et d'un bassin en natation), ou null. */
    public function findMostRecentForTest(PerfTest $test, ?int $poolLength): ?PerfTestSession
    {
        return $this->findRecentForTest($test, $poolLength, 1)[0] ?? null;
    }

    /**
     * Saisons (début d'année, décroissant) où au moins une séance a des
     * temps saisis — pour le sélecteur de l'appli mobile.
     *
     * @return list<int>
     */
    public function findSeasonsWithResults(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.date AS d')
            ->innerJoin('s.results', 'r')
            ->orderBy('s.date', 'DESC')
            ->getQuery()
            ->getScalarResult();

        $seasons = [];
        foreach ($rows as $row) {
            $seasons[self::seasonStartYear(new \DateTimeImmutable((string) $row['d']))] = true;
        }
        $years = array_keys($seasons);
        rsort($years);
        return $years;
    }

    /**
     * Séances d'une saison (1er sept. → 31 août) qui ont au moins un temps,
     * avec leurs temps et adhérents chargés en une requête, la plus
     * récente d'abord.
     *
     * @return list<PerfTestSession>
     */
    public function findWithResultsForSeason(int $startYear): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.results', 'r')->addSelect('r')
            ->leftJoin('r.user', 'u')->addSelect('u') // left : les anciens adhérents n'ont pas de compte
            ->where('s.date >= :from')->setParameter('from', sprintf('%04d-09-01', $startYear))
            ->andWhere('s.date <= :to')->setParameter('to', sprintf('%04d-08-31', $startYear + 1))
            ->orderBy('s.date', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
