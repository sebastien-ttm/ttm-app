<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email), 'UTF-8')]);
    }

    public function findOneByNumLicence(string $numLicence): ?User
    {
        return $this->findOneBy(['numLicence' => $numLicence]);
    }

    /**
     * @return list<User>
     */
    public function findActiveNotSyncedSince(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('u.lastCsvSyncAt IS NULL OR u.lastCsvSyncAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
