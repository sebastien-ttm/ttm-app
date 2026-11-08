<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\VolunteerCampaign;
use App\Entity\VolunteerPole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerPole>
 */
class VolunteerPoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerPole::class);
    }

    /**
     * Pôles d'une campagne dont l'adhérent est responsable.
     *
     * @return list<VolunteerPole>
     */
    public function findManagedBy(User $user, VolunteerCampaign $campaign): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.managers', 'm')
            ->andWhere('m = :user')
            ->andWhere('p.campaign = :campaign')
            ->setParameter('user', $user)
            ->setParameter('campaign', $campaign)
            ->orderBy('p.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
