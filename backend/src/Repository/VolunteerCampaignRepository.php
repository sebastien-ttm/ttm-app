<?php

namespace App\Repository;

use App\Entity\VolunteerCampaign;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerCampaign>
 */
class VolunteerCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerCampaign::class);
    }

    /**
     * Campagnes publiées dont l'événement n'est pas terminé depuis plus de 7 jours, la plus proche d'abord.
     *
     * @return list<VolunteerCampaign>
     */
    public function findVisible(): array
    {
        $limit = new \DateTimeImmutable('-7 days');
        return $this->createQueryBuilder('c')
            ->andWhere('c.published = true')
            ->andWhere('COALESCE(c.endsOn, c.startsOn) >= :limit')
            ->setParameter('limit', $limit->setTime(0, 0))
            ->orderBy('c.startsOn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlug(string $slug): ?VolunteerCampaign
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
