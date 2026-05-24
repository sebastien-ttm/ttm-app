<?php

namespace App\Repository;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingSlot>
 */
class TrainingSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSlot::class);
    }

    /**
     * Tous les créneaux d'une semaine donnée (templates joint pour distinguer overrides / occasionnels).
     *
     * @return list<TrainingSlot>
     */
    public function findForWeek(\DateTimeImmutable $weekStartsAt): array
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);

        return $this->createQueryBuilder('s')
            ->leftJoin('s.template', 't')->addSelect('t')
            ->where('s.weekStartsAt = :w')
            ->setParameter('w', $monday->format('Y-m-d'))
            ->orderBy('s.dayOfWeek', 'ASC')
            ->addOrderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOverride(\DateTimeImmutable $weekStartsAt, TrainingSlotTemplate $template): ?TrainingSlot
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        return $this->findOneBy(['weekStartsAt' => $monday, 'template' => $template]);
    }
}
