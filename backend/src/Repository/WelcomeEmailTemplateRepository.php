<?php

namespace App\Repository;

use App\Entity\WelcomeEmailTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WelcomeEmailTemplate>
 */
class WelcomeEmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WelcomeEmailTemplate::class);
    }

    /**
     * Singleton : renvoie la première (et unique) ligne, ou null si aucune.
     */
    public function findCurrent(): ?WelcomeEmailTemplate
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
