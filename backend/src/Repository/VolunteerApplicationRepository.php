<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\VolunteerApplication;
use App\Entity\VolunteerCampaign;
use App\Entity\VolunteerPole;
use App\Entity\VolunteerRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerApplication>
 */
class VolunteerApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerApplication::class);
    }

    public function findOneByRoleAndUser(VolunteerRole $role, User $user): ?VolunteerApplication
    {
        return $this->findOneBy(['role' => $role, 'user' => $user]);
    }

    /**
     * Candidatures d'un adhérent sur une campagne, les plus récentes d'abord.
     *
     * @return list<VolunteerApplication>
     */
    public function findForUser(User $user, VolunteerCampaign $campaign): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.role', 'r')
            ->innerJoin('r.pole', 'p')
            ->andWhere('a.user = :user')
            ->andWhere('p.campaign = :campaign')
            ->setParameter('user', $user)
            ->setParameter('campaign', $campaign)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Candidatures sur les fiches des pôles donnés, en attente d'abord puis les plus récentes.
     *
     * @param list<VolunteerPole> $poles
     * @return list<VolunteerApplication>
     */
    public function findForPoles(array $poles): array
    {
        if ($poles === []) {
            return [];
        }
        return $this->createQueryBuilder('a')
            ->innerJoin('a.role', 'r')
            ->innerJoin('a.user', 'u')
            ->addSelect('r', 'u')
            ->addSelect("CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN pendingFirst")
            ->andWhere('r.pole IN (:poles)')
            ->setParameter('poles', $poles)
            ->addOrderBy('pendingFirst', 'ASC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @param list<VolunteerPole> $poles */
    public function countPendingForPoles(array $poles): int
    {
        if ($poles === []) {
            return 0;
        }
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->innerJoin('a.role', 'r')
            ->andWhere('r.pole IN (:poles)')
            ->andWhere('a.status = :pending')
            ->setParameter('poles', $poles)
            ->setParameter('pending', VolunteerApplication::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Toutes les candidatures d'une campagne (export CSV), par pôle puis fiche.
     *
     * @return list<VolunteerApplication>
     */
    public function findForCampaign(VolunteerCampaign $campaign): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.role', 'r')
            ->innerJoin('r.pole', 'p')
            ->innerJoin('a.user', 'u')
            ->addSelect('r', 'p', 'u')
            ->andWhere('p.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->addOrderBy('p.position', 'ASC')
            ->addOrderBy('r.kind', 'ASC')
            ->addOrderBy('r.position', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
