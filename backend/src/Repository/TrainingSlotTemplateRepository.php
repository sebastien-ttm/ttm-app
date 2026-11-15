<?php

namespace App\Repository;

use App\Entity\TrainingSlotTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingSlotTemplate>
 */
class TrainingSlotTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSlotTemplate::class);
    }

    /**
     * Identifiants des créneaux de la semaine type cochés « Groupes d'entraînement » : les seules séances sur
     * lesquelles un encadrant peut créer un groupe.
     *
     * @return list<int>
     */
    public function findGroupEnabledIds(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.groupsEnabled = true')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['id'], $rows);
    }

    /**
     * @return list<TrainingSlotTemplate>
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = true')
            ->orderBy('t.dayOfWeek', 'ASC')
            ->addOrderBy('t.startTime', 'ASC')
            ->addOrderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
