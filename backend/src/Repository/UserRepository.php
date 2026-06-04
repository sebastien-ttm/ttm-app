<?php

namespace App\Repository;

use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Enum\Profile;
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
     * Profils accessibles depuis un user. On combine deux mécanismes :
     *   1) E-mail partagé : le primaire + tous les autres rattachés via
     *      linkedToUser (hérité de l'import CSV familial).
     *   2) Relation famille explicite (table user_parent_child) :
     *      - si le user est parent → on ajoute ses enfants
     *      - si le user est enfant → on ajoute ses parents
     *
     * Renvoie une liste dédupliquée par id, ordonnée par date de naissance.
     *
     * @return list<User>
     */
    public function findLinkedProfiles(User $user): array
    {
        $primary = $user->getPrimaryUser();

        // 1) Profils via e-mail partagé
        $emailLinked = $this->createQueryBuilder('u')
            ->where('u = :primary OR u.linkedToUser = :primary')
            ->setParameter('primary', $primary)
            ->getQuery()
            ->getResult();

        // 2) Relation famille (parent → enfants et enfant → parents)
        $byId = [];
        foreach ($emailLinked as $u) {
            $byId[$u->getId()] = $u;
        }
        // Le user courant doit toujours être présent (il l'est déjà via 1
        // dans la plupart des cas, mais on s'assure)
        $byId[$user->getId()] = $user;
        foreach ($user->getChildren() as $child) {
            $byId[$child->getId()] = $child;
        }
        foreach ($user->getParents() as $parent) {
            $byId[$parent->getId()] = $parent;
        }

        $all = array_values($byId);

        // Tri par date de naissance (les sans-date en queue)
        usort($all, static function (User $a, User $b): int {
            $da = $a->getDateNaissance();
            $db = $b->getDateNaissance();
            if ($da === null && $db === null) return $a->getId() <=> $b->getId();
            if ($da === null) return 1;
            if ($db === null) return -1;
            return $da <=> $db;
        });

        return $all;
    }

    public function findOneByNumLicence(string $numLicence): ?User
    {
        $normalized = User::normalizeLicence($numLicence);
        if ($normalized === null) {
            return null;
        }
        return $this->findOneBy(['numLicence' => $normalized]);
    }

    /**
     * Cherche un adhérent actif par n° de licence (normalisé : 7 premiers
     * caractères, uppercase, espaces triés). Utilisé par l'inscription
     * parent mobile pour valider le lien de filiation.
     */
    public function findActiveByLicenceNormalized(string $rawLicence): ?User
    {
        $normalized = User::normalizeLicence($rawLicence);
        if ($normalized === null) {
            return null;
        }
        return $this->createQueryBuilder('u')
            ->where('u.numLicence = :lic')
            ->andWhere('u.isActive = true')
            ->setParameter('lic', $normalized)
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

    /**
     * Liste des destinataires d'un email de notification pour un plan
     * d'entraînement nouvellement publié.
     *
     * Critères (intersection) :
     *  - actif
     *  - email renseigné
     *  - licencié (numLicence non null — exclut les parents externes et amis)
     *  - typeLicence ≠ 'Dirigeant' (cohérent avec la règle canSeeTraining mobile)
     *  - profil NE contient PAS 'jeune' (exclu explicitement par la demande)
     *  - si plan.audience non vide : au moins un profil en intersection
     *    (sinon : visible à tous → on garde l'utilisateur)
     *
     * Dédup par email : un parent et son enfant qui partagent une adresse
     * ne reçoivent qu'un seul mail (le premier rencontré, choix arbitraire
     * stable).
     *
     * @return list<User>
     */
    public function findTrainingPlanEmailRecipients(TrainingPlan $plan): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.numLicence IS NOT NULL')
            ->andWhere("(u.typeLicence IS NULL OR u.typeLicence <> 'Dirigeant')")
            // Exclut les Jeunes : JSON_CONTAINS retourne 1 si présent
            ->andWhere('JSON_CONTAINS(u.profiles, :jeune_tag) = 0')
            ->setParameter('jeune_tag', json_encode(Profile::Jeune->value));

        // Audience ciblée du plan : on garde les users dont au moins un
        // profil intersecte. Plan sans audience → on garde tout le monde.
        $audience = $plan->getAudience();
        if ($audience !== []) {
            $orParts = [];
            foreach ($audience as $i => $p) {
                $key = "aud_{$i}";
                $orParts[] = "JSON_CONTAINS(u.profiles, :{$key}) = 1";
                $qb->setParameter($key, json_encode($p));
            }
            $qb->andWhere('('.implode(' OR ', $orParts).')');
        }

        $users = $qb->getQuery()->getResult();

        // Dédup par email : un parent + un enfant qui partagent l'adresse
        // ne doivent pas recevoir 2 fois le même mail.
        $byEmail = [];
        foreach ($users as $u) {
            $email = mb_strtolower((string) $u->getEmail(), 'UTF-8');
            if ($email === '' || isset($byEmail[$email])) {
                continue;
            }
            $byEmail[$email] = $u;
        }

        return array_values($byEmail);
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
