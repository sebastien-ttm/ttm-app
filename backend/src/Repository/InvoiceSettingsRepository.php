<?php

namespace App\Repository;

use App\Entity\InvoiceSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceSettings>
 */
class InvoiceSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceSettings::class);
    }

    /** Singleton : première (et unique) ligne. */
    public function findCurrent(): ?InvoiceSettings
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.id', 'ASC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
