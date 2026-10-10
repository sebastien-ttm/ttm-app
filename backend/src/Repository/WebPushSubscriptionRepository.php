<?php

namespace App\Repository;

use App\Entity\WebPushSubscription;
use App\Enum\Profile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebPushSubscription>
 */
class WebPushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebPushSubscription::class);
    }

    public function findOneByEndpoint(string $endpoint): ?WebPushSubscription
    {
        return $this->findOneBy(['endpointHash' => WebPushSubscription::hashEndpoint($endpoint)]);
    }

    /**
     * Abonnements des adhérents actifs donnés (tous leurs appareils).
     *
     * @param list<int> $userIds
     * @return list<WebPushSubscription>
     */
    public function findByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        return $this->createQueryBuilder('s')
            ->innerJoin('s.user', 'u')->addSelect('u')
            ->where('s.user IN (:ids)')->setParameter('ids', $userIds)
            ->andWhere('u.isActive = true')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ids des adhérents actifs abonnés et autorisés à voir les plans
     * d'entraînement : tous sauf les comptes Jeune (sauf s'ils sont aussi
     * Entraîneur / Encadrant) — même règle que DeviceTokenRepository.
     *
     * @return list<int>
     */
    public function findUserIdsForTrainingPlans(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT IDENTITY(s.user) AS uid')
            ->innerJoin('s.user', 'u')
            ->where('u.isActive = true')
            ->andWhere('JSON_CONTAINS(u.profiles, :jeune) = 0 OR JSON_CONTAINS(u.profiles, :entraineur) = 1 OR JSON_CONTAINS(u.profiles, :encadrant) = 1')
            ->setParameter('jeune', json_encode(Profile::Jeune->value))
            ->setParameter('entraineur', json_encode(Profile::Entraineur->value))
            ->setParameter('encadrant', json_encode(Profile::Encadrant->value))
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['uid'], $rows);
    }
}
