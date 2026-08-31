<?php

namespace App\Repository;

use App\Entity\CharterAcceptance;
use App\Entity\ClubCharter;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharterAcceptance>
 */
class CharterAcceptanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharterAcceptance::class);
    }

    public function findOneBy_($user, $charter): ?CharterAcceptance
    {
        return $this->findOneBy(['user' => $user, 'charter' => $charter]);
    }

    public function hasAccepted(User $user, ClubCharter $charter): bool
    {
        return $this->findOneBy(['user' => $user, 'charter' => $charter]) !== null;
    }

    /**
     * Nombre total d'acceptations passées d'un user (toutes chartes
     * confondues). Sert à qualifier un parent externe comme
     * « renouvellement » quand il n'a pas d'adhésion propre à compter.
     */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.user = :u')->setParameter('u', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<int>  user IDs who have NOT accepted the given charter
     */
    public function findMissingAcceptances(ClubCharter $charter): array
    {
        $em = $this->getEntityManager();
        $sql = '
            SELECT u.id
            FROM `user` u
            WHERE u.is_active = 1
              AND u.id NOT IN (
                  SELECT a.user_id FROM charter_acceptance a WHERE a.charter_id = :charterId
              )
        ';
        $rows = $em->getConnection()->fetchAllAssociative($sql, ['charterId' => $charter->getId()]);
        return array_map(fn ($r) => (int) $r['id'], $rows);
    }
}
