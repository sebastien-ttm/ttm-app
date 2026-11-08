<?php

namespace App\Repository;

use App\Entity\BoardAgendaItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardAgendaItem>
 */
class BoardAgendaItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardAgendaItem::class);
    }
}
