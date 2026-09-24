<?php

namespace App\Repository;

use App\Entity\EmailChangeRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailChangeRequest>
 */
class EmailChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailChangeRequest::class);
    }

    public function findOneByTokenHash(string $hash): ?EmailChangeRequest
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * Demandes encore utilisables d'un user (non consommées, non expirées).
     *
     * @return list<EmailChangeRequest>
     */
    public function findPendingForUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :u')
            ->andWhere('r.usedAt IS NULL')
            ->andWhere('r.expiresAt > :now')
            ->setParameter('u', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function findLatestForUser(User $user): ?EmailChangeRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :u')
            ->setParameter('u', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
