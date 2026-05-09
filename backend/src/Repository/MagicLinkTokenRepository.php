<?php

namespace App\Repository;

use App\Entity\MagicLinkToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagicLinkToken>
 */
class MagicLinkTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagicLinkToken::class);
    }

    public function findOneByTokenHash(string $hash): ?MagicLinkToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    public function deleteExpired(\DateTimeImmutable $now = null): int
    {
        $now = $now ?? new \DateTimeImmutable();
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :now')
            ->orWhere('t.usedAt IS NOT NULL AND t.createdAt < :weekAgo')
            ->setParameter('now', $now)
            ->setParameter('weekAgo', $now->modify('-7 days'))
            ->getQuery()
            ->execute();
    }
}
