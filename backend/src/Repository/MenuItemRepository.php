<?php

namespace App\Repository;

use App\Entity\MenuItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MenuItem>
 */
class MenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MenuItem::class);
    }

    /**
     * @return list<MenuItem>
     */
    public function findVisibleOrdered(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.isVisible = true')
            ->orderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
