<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\WebPushSubscription;
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
     * Adhérents actifs ayant au moins un appareil abonné aux notifications push (candidats à une
     * notification : le filtrage par sujet et par audience se fait ensuite en mémoire).
     *
     * @return list<User>
     */
    public function findSubscribedUsers(): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.isActive = true')
            ->andWhere('EXISTS (SELECT 1 FROM '.WebPushSubscription::class.' s WHERE s.user = u)')
            ->getQuery()
            ->getResult();
    }
}
