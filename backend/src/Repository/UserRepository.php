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

    /**
     * Trouve l'utilisateur PRIMAIRE (linkedToUser = NULL) pour un e-mail donné.
     * C'est ce user qui peut se connecter ; ses dépendants (parent/enfants
     * partageant le même e-mail) sont accessibles via un switch de profil.
     */
    public function findOneByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->andWhere('u.linkedToUser IS NULL')
            ->setParameter('email', mb_strtolower(trim($email), 'UTF-8'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Tous les users actifs partageant un e-mail (primaire + liés).
     *
     * @return list<User>
     */
    public function findAllActiveByEmail(string $email): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->andWhere('u.isActive = true')
            ->setParameter('email', mb_strtolower(trim($email), 'UTF-8'))
            ->orderBy('u.dateNaissance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Profils accessibles depuis un user (primaire ou lié) : le primaire et
     * tous les autres rattachés au même primaire.
     *
     * @return list<User>
     */
    public function findLinkedProfiles(User $user): array
    {
        $primary = $user->getPrimaryUser();

        return $this->createQueryBuilder('u')
            ->where('u = :primary OR u.linkedToUser = :primary')
            ->setParameter('primary', $primary)
            ->orderBy('u.dateNaissance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNumLicence(string $numLicence): ?User
    {
        return $this->findOneBy(['numLicence' => $numLicence]);
    }

    /**
     * Cherche un adhérent actif par n° de licence (case-insensitive, espaces
     * triés). Utilisé par l'inscription parent mobile pour valider le lien
     * de filiation.
     */
    public function findActiveByLicenceNormalized(string $rawLicence): ?User
    {
        $cleaned = strtoupper(trim($rawLicence));
        if ($cleaned === '') {
            return null;
        }
        return $this->createQueryBuilder('u')
            ->where('UPPER(u.numLicence) = :lic')
            ->andWhere('u.isActive = true')
            ->setParameter('lic', $cleaned)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Utilisateurs actifs qui n'ont pas été touchés par le dernier import CSV
     * et qui sont éligibles à la désactivation.
     *
     * On exclut :
     *  - les comptes admin (role='admin') : ajoutés à la main, ne dépendent
     *    pas du flux FFTri.
     *  - les comptes externes (type='externe') : créés via inscription
     *    mobile (parents), n'apparaissent pas dans le CSV.
     *
     * @return list<User>
     */
    public function findActiveNotSyncedSince(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('u.lastCsvSyncAt IS NULL OR u.lastCsvSyncAt < :cutoff')
            ->andWhere("u.role <> 'admin'")
            ->andWhere("u.type = 'adherent'")
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
