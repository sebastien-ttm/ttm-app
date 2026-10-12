<?php

namespace App\Repository;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    /**
     * Créneaux occasionnels (sans semaine type) identiques à celui-ci — même jour, heure, durée, sport,
     * titre et lieu — sur les autres semaines, lui compris. `$fromWeek` : ne garder que cette semaine et les
     * suivantes (null = toutes les semaines). Sert à supprimer en une fois un créneau devenu « occasionnel »
     * chaque semaine (ex. issu d'une semaine type supprimée).
     *
     * @return list<TrainingSlot>
     */
    public function findOccasionalTwins(TrainingSlot $slot, ?\DateTimeImmutable $fromWeek = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.template IS NULL')
            ->andWhere('s.dayOfWeek = :day')
            ->andWhere('s.startTime = :start')
            ->andWhere('s.durationMinutes = :duration')
            ->andWhere('s.sport = :sport')
            ->andWhere('s.title = :title')
            ->andWhere('s.location = :location')
            ->setParameter('day', $slot->getDayOfWeek())
            ->setParameter('start', $slot->getStartTime(), Types::TIME_IMMUTABLE)
            ->setParameter('duration', $slot->getDurationMinutes())
            ->setParameter('sport', $slot->getSport())
            ->setParameter('title', $slot->getTitle())
            ->setParameter('location', $slot->getLocation())
            ->orderBy('s.weekStartsAt', 'ASC');

        if ($fromWeek !== null) {
            $qb->andWhere('s.weekStartsAt >= :from')
                ->setParameter('from', $fromWeek->modify('monday this week')->format('Y-m-d'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Occurrences hebdomadaires matérialisées d'une semaine type (overrides, annulations, créneaux portant des
     * présences staff…), de la semaine donnée et des suivantes.
     *
     * @return list<TrainingSlot>
     */
    public function findByTemplateFromWeek(TrainingSlotTemplate $template, \DateTimeImmutable $fromWeek): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.template = :template')
            ->andWhere('s.weekStartsAt >= :from')
            ->setParameter('template', $template)
            ->setParameter('from', $fromWeek->modify('monday this week')->format('Y-m-d'))
            ->getQuery()
            ->getResult();
    }
}
