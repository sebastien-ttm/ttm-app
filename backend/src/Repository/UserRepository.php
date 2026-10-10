<?php

namespace App\Repository;

use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Enum\Profile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
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
     * Point d'entrée du firewall Symfony (json_login + form_login admin) —
     * délègue à findOneByEmail pour garantir que le user chargé est bien
     * le compte PRIMAIRE de l'email (linkedToUser IS NULL), pas juste le
     * premier match. Sans ça, après un swap manuel de primaire dans
     * l'admin, l'ancien primaire (avec l'ancien mot de passe) restait
     * chargé → 401 systématique.
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->findOneByEmail($identifier);
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
        // 1) Profils via e-mail partagé — on compare directement sur
        //    user.email (au lieu de user = primary OR linkedToUser = primary).
        //    Résout deux cas où l'ancienne requête ratait des profils :
        //    - deux comptes marqués primaires par erreur (linkedToUser null
        //      pour les deux) : l'ancien filter n'en voyait qu'un.
        //    - primaire déplacé manuellement dans l'admin sans rebranchement
        //      des anciens dépendants.
        $email = mb_strtolower(trim((string) $user->getEmail()), 'UTF-8');
        $emailLinked = [];
        if ($email !== '') {
            $emailLinked = $this->createQueryBuilder('u')
                ->where('LOWER(u.email) = :email')
                ->setParameter('email', $email)
                ->getQuery()
                ->getResult();
        }

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
     * Cherche un adhérent actif par n° de licence (normalisé : 6 premiers
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
     *  - les adhérents externes (licenciés dans un autre club, absents du CSV par
     *    définition) que l'admin a ACTIVÉS pour la saison $protectedSeason : tant que
     *    cette activation tient, l'import ne les désactive pas.
     *
     * @return list<User>
     */
    public function findActiveNotSyncedSince(\DateTimeImmutable $cutoff, ?\App\Entity\TrainingSeason $protectedSeason = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('u.lastCsvSyncAt IS NULL OR u.lastCsvSyncAt < :cutoff')
            ->andWhere("u.role <> 'admin'")
            ->andWhere("u.type = 'adherent'")
            // Comptes temporaires (licence en attente) : absents du CSV
            // par définition, ils ne doivent pas être désactivés.
            ->andWhere('u.pendingLicenceSince IS NULL')
            ->setParameter('cutoff', $cutoff);

        if ($protectedSeason !== null) {
            $qb->andWhere('u.subType IS NULL OR u.subType <> :autreClub OR NOT EXISTS ('
                .'SELECT m.id FROM '.\App\Entity\UserSeasonMembership::class.' m WHERE m.user = u AND m.season = :protectedSeason)')
                ->setParameter('autreClub', User::SUBTYPE_AUTRE_CLUB)
                ->setParameter('protectedSeason', $protectedSeason);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Adhérents externes : comptes d'adhérents licenciés dans un AUTRE club, créés depuis
     * l'appli (« Créer un compte adhérent externe »). Actifs ou non, par ordre alphabétique.
     *
     * @return list<User>
     */
    public function findExternalMembers(): array
    {
        return $this->createQueryBuilder('u')
            ->where("u.type = 'adherent'")
            ->andWhere('u.subType = :autreClub')
            ->setParameter('autreClub', User::SUBTYPE_AUTRE_CLUB)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Adhérents externes dont le compte est actif mais pas encore activé pour la saison donnée. */
    public function countExternalMembersToActivate(\App\Entity\TrainingSeason $season): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where("u.type = 'adherent'")
            ->andWhere('u.subType = :autreClub')
            ->andWhere('u.isActive = true')
            ->andWhere('NOT EXISTS ('
                .'SELECT m.id FROM '.\App\Entity\UserSeasonMembership::class.' m WHERE m.user = u AND m.season = :season)')
            ->setParameter('autreClub', User::SUBTYPE_AUTRE_CLUB)
            ->setParameter('season', $season)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Comptes temporaires en attente de licence, le plus ancien d'abord.
     *
     * @return list<User>
     */
    public function findPendingLicence(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.pendingLicenceSince IS NOT NULL')
            ->orderBy('u.pendingLicenceSince', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte temporaire correspondant à une ligne du CSV FFTri : même
     * date de naissance, et même nom + prénom (sans tenir compte des
     * accents, de la casse, des tirets ni des espaces) ou, à défaut, même
     * email (nom mal orthographié à la saisie). null si aucun ou si
     * plusieurs comptes correspondent (cas ambigu laissé à l'admin).
     *
     * @param list<string> $noms nom de naissance et nom d'usage
     */
    public function findPendingLicenceMatch(array $noms, string $prenom, \DateTimeImmutable $dateNaissance, string $email): ?User
    {
        $candidates = $this->createQueryBuilder('u')
            ->where('u.pendingLicenceSince IS NOT NULL')
            ->andWhere('u.dateNaissance = :d')
            ->setParameter('d', $dateNaissance->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        $wantedNoms = array_filter(array_map([self::class, 'normalizeName'], $noms));
        $wantedPrenom = self::normalizeName($prenom);
        $byName = array_values(array_filter($candidates, fn (User $u) => self::normalizeName($u->getPrenom()) === $wantedPrenom
            && in_array(self::normalizeName($u->getNom()), $wantedNoms, true)));
        if (count($byName) === 1) {
            return $byName[0];
        }
        if ($byName !== [] || $email === '') {
            return null;
        }

        $byEmail = array_values(array_filter($candidates, fn (User $u) => mb_strtolower($u->getEmail()) === mb_strtolower($email)));
        return count($byEmail) === 1 ? $byEmail[0] : null;
    }

    /** « Jean-Pierre  D'ÉTÉ » → « jeanpierredete ». */
    public static function normalizeName(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($translit !== false && $translit !== '') {
            $s = $translit;
        }
        return (string) preg_replace('/[^a-z]/', '', $s);
    }

    /**
     * Comptes « parents externes » : existent uniquement parce qu'ils ont
     * un ou plusieurs enfants adhérents rattachés. Leur validité doit
     * suivre celle de leurs enfants : plus d'enfant actif → désactivation ;
     * un enfant redevient actif → réactivation.
     *
     * @return list<User>
     */
    public function findExternalParents(): array
    {
        return $this->createQueryBuilder('u')
            ->where("u.type = 'externe' AND u.subType = 'parent'")
            ->andWhere("u.role <> 'admin'")
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
            // Opt-in obligatoire (case à cocher dans le profil mobile,
            // défaut FALSE depuis la migration Version20260609211458).
            ->andWhere('u.notifyTrainingPlanEmail = true')
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

    /**
     * Destinataires éligibles à la notification email d'un nouvel article.
     * Miroir de findTrainingPlanEmailRecipients() — même sémantique :
     *  - opt-in obligatoire (u.notifyArticleEmail = true)
     *  - actif, email présent
     *  - filtre d'audience de l'article (Article utilise AudienceAwareTrait)
     *  - dédup par email pour éviter les doublons parent/enfant
     *
     * Différence avec les plans : PAS d'exclusion Jeune / typeLicence
     * Dirigeant / licence obligatoire. Les articles s'adressent à tout
     * le club — l'audience de l'article est la seule restriction.
     *
     * @return list<User>
     */
    public function findArticleEmailRecipients(\App\Entity\Article $article): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.notifyArticleEmail = true');

        $audience = $article->getAudience();
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

        // Dédup par email — même parent/enfant qui partagent une adresse.
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

    /**
     * Comptes ciblés par un mailing : actifs, avec une adresse e-mail, ni adhérents
     * externes (sauf si le mailing les inclut) ni hors de l'audience par profil
     * (audience vide = tous). Les désinscrits sont INCLUS ici : c'est à l'appelant
     * (MailingService) de les écarter et de les compter, pour l'afficher à l'admin.
     *
     * @return list<User> triés par nom, prénom
     */
    public function findMailingAudience(\App\Entity\Mailing $mailing): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('u.email IS NOT NULL')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

        if (!$mailing->isIncludeExternal()) {
            $qb->andWhere("u.type = 'adherent'");
        }

        $audience = $mailing->getAudience();
        if ($audience !== []) {
            $orParts = [];
            foreach ($audience as $i => $p) {
                $key = "aud_{$i}";
                $orParts[] = "JSON_CONTAINS(u.profiles, :{$key}) = 1";
                $qb->setParameter($key, json_encode($p));
            }
            $qb->andWhere('('.implode(' OR ', $orParts).')');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Tous les utilisateurs actifs ayant un rôle backend donné.
     * Utilisé pour les notifications (ex : email à tous les admins quand
     * un message « au club » est reçu).
     *
     * @return list<User>
     */
    public function findActiveByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.role = :role')
            ->setParameter('role', $role)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Adhérents actifs pour le trombinoscope admin, ordonnés nom + prénom.
     * On garde uniquement les type='adherent' — les comptes externes
     * (parents non-licenciés, amis) n'ont pas leur place dans ce recap.
     *
     * @return list<User>
     */
    public function findActiveAdherentsForRecap(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere("u.type = 'adherent'")
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Annuaire des adhérents actifs pour le staff (appli mobile) : triés par
     * nom puis prénom, avec leurs parents chargés en même temps (le numéro
     * d'un parent sert d'appel d'urgence pour un enfant sans téléphone).
     *
     * @return list<User>
     */
    public function findAdherentsForStaffDirectory(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.parents', 'p')->addSelect('p')
            ->where('u.isActive = true')
            ->andWhere("u.type = 'adherent'")
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
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

    /**
     * Users actifs ayant un rôle CoDir (Bureau + Membres CoDir).
     *
     * @return list<User>
     */
    public function findCommitteeMembers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.boardRole IS NOT NULL')
            ->andWhere('u.isActive = 1')
            ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Users actifs avec le profil Entraîneur. On matche via LIKE sur le
     * JSON stocké — même approche que le reste du repo pour les profils.
     *
     * @return list<User>
     */
    public function findCoaches(): array
    {
        return $this->findByProfile(Profile::Entraineur);
    }

    /**
     * Users actifs avec le profil Encadrant.
     *
     * @return list<User>
     */
    public function findEncadrants(): array
    {
        return $this->findByProfile(Profile::Encadrant);
    }

    /**
     * @return list<User>
     */
    private function findByProfile(Profile $profile): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.isActive = 1')
            ->andWhere('u.profiles LIKE :p')
            ->setParameter('p', '%"'.$profile->value.'"%')
            ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
            ->getQuery()->getResult();
    }
}
