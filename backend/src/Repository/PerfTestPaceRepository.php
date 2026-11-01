<?php

namespace App\Repository;

use App\Entity\PerfTestPace;
use App\Enum\PerfTest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerfTestPace>
 */
class PerfTestPaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerfTestPace::class);
    }

    /**
     * Toutes les échelles d'allure enregistrées, de la saison la plus ancienne à la plus récente.
     *
     * @return list<PerfTestPace>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.seasonStartYear', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** L'échelle d'une épreuve pour une saison précise (null si cette saison n'a pas la sienne). */
    public function findOneFor(PerfTest $test, int $seasonStartYear): ?PerfTestPace
    {
        return $this->findOneBy(['test' => $test, 'seasonStartYear' => $seasonStartYear]);
    }

    /**
     * Saisons (année de début) pour lesquelles des intervalles sont enregistrés, de la plus récente à la plus ancienne.
     *
     * @return list<int>
     */
    public function findSeasonYears(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.seasonStartYear AS y')
            ->orderBy('p.seasonStartYear', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['y'], $rows);
    }
}
