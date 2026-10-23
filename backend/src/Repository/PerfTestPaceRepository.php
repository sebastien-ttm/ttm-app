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
     * Les échelles d'allure enregistrées, par valeur d'épreuve (`run_1500`…).
     *
     * @return array<string, PerfTestPace>
     */
    public function findAllByTest(): array
    {
        $out = [];
        foreach ($this->findAll() as $pace) {
            $out[$pace->getTest()->value] = $pace;
        }

        return $out;
    }

    public function findOneByTest(PerfTest $test): ?PerfTestPace
    {
        return $this->findOneBy(['test' => $test]);
    }
}
