<?php

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
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
}
