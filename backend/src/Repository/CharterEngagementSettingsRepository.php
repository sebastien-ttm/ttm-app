<?php

namespace App\Repository;

use App\Entity\CharterEngagementSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharterEngagementSettings>
 */
class CharterEngagementSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharterEngagementSettings::class);
    }

    /** Singleton : première (et unique) ligne. */
    public function findCurrent(): ?CharterEngagementSettings
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.id', 'ASC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Renvoie la liste d'engagements courante (jamais null). Tableau vide
     * si le singleton n'existe pas ou est vide.
     *
     * @return list<array<string, mixed>>
     */
    public function currentFields(): array
    {
        $s = $this->findCurrent();
        return $s?->getFields() ?? [];
    }
}
