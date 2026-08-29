<?php

namespace App\Repository;

use App\Entity\GouterCancellation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GouterCancellation>
 */
class GouterCancellationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GouterCancellation::class);
    }

    /**
     * Retourne les annulations dans un intervalle, indexées par 'Y-m-d'
     * pour un lookup rapide en boucle.
     *
     * @return array<string, GouterCancellation>
     */
    public function findMapInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('c')
            ->where('c.date BETWEEN :from AND :to')
            ->setParameter('from', $from->setTime(0, 0, 0)->format('Y-m-d'))
            ->setParameter('to', $to->setTime(0, 0, 0)->format('Y-m-d'))
            ->getQuery()->getResult();

        $map = [];
        foreach ($rows as $c) {
            /** @var GouterCancellation $c */
            $map[$c->getDate()->format('Y-m-d')] = $c;
        }
        return $map;
    }

    public function findOneByDate(\DateTimeImmutable $date): ?GouterCancellation
    {
        return $this->createQueryBuilder('c')
            ->where('c.date = :d')->setParameter('d', $date->setTime(0, 0, 0)->format('Y-m-d'))
            ->getQuery()->getOneOrNullResult();
    }
}
