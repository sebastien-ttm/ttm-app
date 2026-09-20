<?php

namespace App\Repository;

use App\Entity\TrainingPlan;
use App\Entity\TrainingPlanOpen;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingPlanOpen>
 */
class TrainingPlanOpenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingPlanOpen::class);
    }

    public function findOneByUserAndPlan(User $user, TrainingPlan $plan): ?TrainingPlanOpen
    {
        return $this->findOneBy(['user' => $user, 'plan' => $plan]);
    }

    /**
     * Comptage des ouvertures uniques par programme, groupées par semaine
     * (weekStartsAt du plan). Un adhérent est compté une seule fois par
     * plan (garanti par l'index unique user_id + plan_id).
     *
     * @return list<array{
     *     planId: int,
     *     title: string,
     *     category: string,
     *     weekStartsAt: ?\DateTimeImmutable,
     *     openCount: int
     * }>
     */
    public function weeklyDistinctOpens(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('IDENTITY(o.plan) AS planId', 'p.title AS title', 'p.category AS category', 'p.weekStartsAt AS weekStartsAt', 'COUNT(DISTINCT o.user) AS openCount')
            ->join('o.plan', 'p')
            ->groupBy('o.plan')
            ->orderBy('p.weekStartsAt', 'DESC')
            ->addOrderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r) => [
            'planId' => (int) $r['planId'],
            'title' => (string) $r['title'],
            'category' => (string) $r['category'],
            'weekStartsAt' => $r['weekStartsAt'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($r['weekStartsAt'])
                : null,
            'openCount' => (int) $r['openCount'],
        ], $rows);
    }
}
