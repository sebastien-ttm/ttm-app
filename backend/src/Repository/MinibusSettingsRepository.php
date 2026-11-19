<?php

namespace App\Repository;

use App\Entity\MinibusSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MinibusSettings>
 */
class MinibusSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MinibusSettings::class);
    }

    public function findCurrent(): ?MinibusSettings
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Les réglages en vigueur ; à défaut, des réglages par défaut (non enregistrés) avec le texte de charte d'origine. */
    public function current(): MinibusSettings
    {
        return $this->findCurrent() ?? new MinibusSettings();
    }
}
