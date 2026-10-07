<?php

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\Profile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceToken>
 */
class DeviceTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceToken::class);
    }

    public function findOneByToken(string $expoPushToken): ?DeviceToken
    {
        return $this->findOneBy(['expoPushToken' => $expoPushToken]);
    }

    /**
     * @return list<string> all expo push tokens for active users
     */
    public function findAllActiveExpoTokens(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.expoPushToken')
            ->leftJoin('d.user', 'u')
            ->where('u.isActive = true')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn ($r) => $r['expoPushToken'], $rows);
    }

    /**
     * Tokens des utilisateurs actifs autorisés à voir les plans
     * d'entraînement : tous sauf les comptes Jeune (sauf s'ils sont
     * aussi Entraîneur / Encadrant) — cf. User::canSeeTrainingPlans().
     *
     * @return list<string>
     */
    public function findActiveExpoTokensForTrainingPlans(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.expoPushToken')
            ->leftJoin('d.user', 'u')
            ->where('u.isActive = true')
            ->andWhere('JSON_CONTAINS(u.profiles, :jeune) = 0 OR JSON_CONTAINS(u.profiles, :entraineur) = 1 OR JSON_CONTAINS(u.profiles, :encadrant) = 1')
            ->setParameter('jeune', json_encode(Profile::Jeune->value))
            ->setParameter('entraineur', json_encode(Profile::Entraineur->value))
            ->setParameter('encadrant', json_encode(Profile::Encadrant->value))
            ->getQuery()
            ->getArrayResult();

        return array_values(array_unique(array_map(fn ($r) => $r['expoPushToken'], $rows)));
    }
}
