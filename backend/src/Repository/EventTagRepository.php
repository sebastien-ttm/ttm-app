<?php

namespace App\Repository;

use App\Entity\EventTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventTag>
 */
class EventTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventTag::class);
    }

    /**
     * Tags actifs, ordonnés par position puis nom — pour peupler le
     * sélecteur admin (form create/edit d'un événement).
     *
     * @return list<EventTag>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
