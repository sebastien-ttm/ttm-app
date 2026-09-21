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

        return array_map(static function (array $r): array {
            // `category` sort de DQL comme un BackedEnum (TrainingPlanCategory) —
            // Doctrine ne le cast pas en scalaire dans un SELECT partiel.
            $cat = $r['category'];
            if ($cat instanceof \BackedEnum) {
                $cat = $cat->value;
            }
            return [
                'planId' => (int) $r['planId'],
                'title' => (string) $r['title'],
                'category' => (string) $cat,
                'weekStartsAt' => $r['weekStartsAt'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($r['weekStartsAt'])
                    : null,
                'openCount' => (int) $r['openCount'],
            ];
        }, $rows);
    }
}
