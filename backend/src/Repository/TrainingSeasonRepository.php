<?php

namespace App\Repository;

use App\Entity\TrainingSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingSeason>
 */
class TrainingSeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSeason::class);
    }

    /**
     * Saison « courante » = celle qui contient la date passée (ou aujourd'hui
     * par défaut). Si aucune ne matche, on retourne la plus récente par
     * startsAt (utile pendant l'intersaison ou pour la préparation d'une
     * saison future).
     *
     * NB : l'ancienne implémentation ordonnait par id ASC (renvoyait la
     * plus VIEILLE saison) — un bug ancien qui rendait toute nouvelle
     * saison invisible tant que la première n'était pas supprimée.
     */
    public function findCurrent(?\DateTimeImmutable $date = null): ?TrainingSeason
    {
        $date ??= new \DateTimeImmutable('today');

        // 1. Saison dont la plage contient la date (ou dates ouvertes des 2 côtés)
        $active = $this->createQueryBuilder('s')
            ->where('s.startsAt IS NULL OR s.startsAt <= :d')
            ->andWhere('s.endsAt IS NULL OR s.endsAt >= :d')
            ->setParameter('d', $date->format('Y-m-d'))
            ->orderBy('s.startsAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($active !== null) {
            return $active;
        }

        // 2. Fallback : saison la plus récente par startsAt (permet de préparer
        //    une saison future ou de rester cohérent hors-période).
        return $this->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOrCreate(): TrainingSeason
    {
        $s = $this->findCurrent();
        if ($s !== null) {
            return $s;
        }
        $s = new TrainingSeason();
        $this->getEntityManager()->persist($s);
        $this->getEntityManager()->flush();
        return $s;
    }
}
