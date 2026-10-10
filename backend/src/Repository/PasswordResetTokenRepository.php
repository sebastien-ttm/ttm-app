<?php

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findOneByTokenHash(string $hash): ?PasswordResetToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** Invalide les liens encore valides d'un adhérent (seul le dernier lien demandé doit fonctionner). */
    public function invalidateUnusedForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':now')
            ->where('t.user = :user')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /** Supprime les jetons expirés, ou utilisés depuis plus de 7 jours. */
    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :now')
            ->orWhere('t.usedAt IS NOT NULL AND t.createdAt < :weekAgo')
            ->setParameter('now', $now)
            ->setParameter('weekAgo', $now->modify('-7 days'))
            ->getQuery()
            ->execute();
    }
}
