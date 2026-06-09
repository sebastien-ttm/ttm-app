<?php

namespace App\Entity;

use App\Enum\Profile;
use App\Enum\UserType;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_user_num_licence', columns: ['num_licence'])]
// email n'est plus unique : parent et enfants peuvent partager une boîte
#[ORM\Index(name: 'idx_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $numLicence = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $nom;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $prenom;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    /**
     * Provenance du compte (CSV FFTri vs inscription externe).
     */
    #[ORM\Column(length: 16, enumType: UserType::class)]
    private UserType $type = UserType::Adherent;

    /**
     * Sous-type, dépendant de `type` :
     *   Pour adherent  : 'club' (licencié au club, défaut import CSV)
     *                    ou 'autre_club' (licencié ailleurs — créé manuellement).
     *   Pour externe   : 'parent' (lien avec un enfant adhérent)
     *                    ou 'ami' (ancien adhérent, soutien…).
     * On garde un simple string pour rester ouvert à de futures valeurs sans
     * casser le schéma.
     */
    #[ORM\Column(length: 32, options: ['default' => 'club'])]
    private string $subType = 'club';

    #[ORM\Column(length: 40)]
    private string $statutLicence = 'Actif';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    /** 'm' (homme) ou 'f' (femme), nullable si non renseigné */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $sexe = null;

    /** Adresse postale complète (concaténée à l'import) */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adresse = null;

    /** "Compétition", "Loisir", "Dirigeant" — dérivé du Type de licence FFTri */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $typeLicence = null;

    /** Ex. "Senior 1", "Cadet 2", "Vétéran 3" — copié tel quel depuis FFTri */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $categorieAge = null;

    /**
     * Liste des profils de l'utilisateur (jeune/senior/u25/parent/encadrant).
     * Jeune et Senior sont auto-assignés à l'import CSV selon l'âge ;
     * les autres sont gérés à la main par l'admin (ou créés via inscription
     * parent côté mobile dans une phase ultérieure).
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $profiles = [];

    /**
     * Profil "principal" auquel ce user est rattaché (parent/enfant via e-mail
     * partagé). NULL si ce user est lui-même primaire (= peut se connecter).
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'dependents')]
    #[ORM\JoinColumn(name: 'linked_to_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $linkedToUser = null;

    /**
     * Dépendants rattachés à ce user (= ce user est le primaire d'un groupe).
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'linkedToUser')]
    private Collection $dependents;

    /**
     * Enfants adhérents rattachés à ce compte parent. Different du
     * mécanisme linkedToUser (qui ne sert qu'au cas e-mail partagé).
     * Posé via l'inscription parent mobile ou la main de l'admin.
     *
     * @var Collection<int, User>
     */
    #[ORM\JoinTable(name: 'user_parent_child')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'child_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'parents')]
    private Collection $children;

    /**
     * Parents (au sens famille) rattachés à ce compte enfant.
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: self::class, mappedBy: 'children')]
    private Collection $parents;

    /**
     * Niveau d'accès simple : 'user' (mobile uniquement) ou 'admin' (accès backend).
     * Les permissions fines (sections accessibles dans l'admin) sont dérivées
     * du profile (un Entraîneur ne voit que les sections Entraînements, etc.).
     */
    #[ORM\Column(length: 16)]
    private string $role = 'user';

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCsvSyncAt = null;

    /** Nom de fichier de l'avatar (sous public/uploads/avatars/), null = aucun. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarFilename = null;

    /** Date de la dernière connexion réussie (mobile JWT ou admin form). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** Compteur cumulé de connexions réussies. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $loginCount = 0;

    /**
     * Préférence opt-in : recevoir un email à chaque publication de plan
     * d'entraînement. Défaut FALSE — l'adhérent doit cocher la case dans
     * son profil mobile pour s'abonner.
     */
    #[ORM\Column(name: 'notify_training_plan_email', options: ['default' => false])]
    private bool $notifyTrainingPlanEmail = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, DeviceToken>
     */
    #[ORM\OneToMany(targetEntity: DeviceToken::class, mappedBy: 'user', orphanRemoval: true, cascade: ['remove'])]
    private Collection $deviceTokens;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->deviceTokens = new ArrayCollection();
        $this->dependents = new ArrayCollection();
        $this->children = new ArrayCollection();
        $this->parents = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Longueur "stable" du numéro de licence FFTri.
     * Au-delà de 7 caractères, le suffixe peut varier (saison, sous-club…),
     * on ne conserve donc que le préfixe pour les comparaisons.
     */
    public const LICENCE_PREFIX_LENGTH = 7;

    /**
     * Normalise un n° de licence : trim, uppercase, tronqué aux 7 premiers caractères.
     * Renvoie null si vide. Utiliser systématiquement avant lookup / setNumLicence.
     */
    public static function normalizeLicence(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $cleaned = strtoupper(trim($raw));
        if ($cleaned === '') {
            return null;
        }
        return mb_substr($cleaned, 0, self::LICENCE_PREFIX_LENGTH);
    }

    public function getNumLicence(): ?string
    {
        return $this->numLicence;
    }

    /**
     * Tronque automatiquement aux 7 premiers caractères. La validation
     * d'unicité côté DB s'applique donc sur le préfixe stable.
     */
    public function setNumLicence(?string $numLicence): self
    {
        $this->numLicence = self::normalizeLicence($numLicence);
        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): self
    {
        $this->prenom = $prenom;
        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email), 'UTF-8');
        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): self
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getType(): UserType
    {
        return $this->type;
    }

    public function setType(UserType $type): self
    {
        $this->type = $type;
        // Si le subType courant n'est plus valide pour ce type, on retombe
        // sur la première valeur autorisée (= valeur par défaut métier).
        $allowed = self::allowedSubTypes($type);
        if (!in_array($this->subType, $allowed, true)) {
            $this->subType = $allowed[0];
        }
        return $this;
    }

    public function isAdherent(): bool { return $this->type === UserType::Adherent; }
    public function isExterne(): bool { return $this->type === UserType::Externe; }

    public const SUBTYPE_CLUB = 'club';
    public const SUBTYPE_AUTRE_CLUB = 'autre_club';
    public const SUBTYPE_PARENT = 'parent';
    public const SUBTYPE_AMI = 'ami';

    /** @return list<string> Valeurs autorisées selon le type courant. */
    public static function allowedSubTypes(UserType $type): array
    {
        return match ($type) {
            UserType::Adherent => [self::SUBTYPE_CLUB, self::SUBTYPE_AUTRE_CLUB],
            UserType::Externe => [self::SUBTYPE_PARENT, self::SUBTYPE_AMI],
        };
    }

    public function getSubType(): string { return $this->subType; }

    public function setSubType(string $subType): self
    {
        if (!in_array($subType, self::allowedSubTypes($this->type), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Sous-type "%s" invalide pour le type "%s".', $subType, $this->type->value
            ));
        }
        $this->subType = $subType;
        return $this;
    }

    public function isLicencieClub(): bool { return $this->isAdherent() && $this->subType === self::SUBTYPE_CLUB; }
    public function isLicencieAutreClub(): bool { return $this->isAdherent() && $this->subType === self::SUBTYPE_AUTRE_CLUB; }
    public function isParentExterne(): bool { return $this->isExterne() && $this->subType === self::SUBTYPE_PARENT; }
    public function isAmiDuClub(): bool { return $this->isExterne() && $this->subType === self::SUBTYPE_AMI; }

    /**
     * Catégorie principale (Jeune ou Senior) dérivée du profile.
     * Conservée pour rétrocompat ; null si aucun des deux profils n'est posé.
     */
    public function getCategorieLabel(): ?string
    {
        if ($this->hasProfile(Profile::Jeune)) return 'Jeune';
        if ($this->hasProfile(Profile::Senior)) return 'Sénior';
        return null;
    }

    /**
     * Catégorie d'âge FFTRi calculée à la volée depuis la date de naissance.
     * La règle FFTRi est : âge dans l'année courante (année civile - année
     * de naissance, sans tenir compte du jour de l'anniversaire).
     *
     * Retourne null si la date de naissance n'est pas connue (compte externe
     * par exemple).
     */
    public function getCategorieFFTri(?\DateTimeImmutable $now = null): ?string
    {
        if ($this->dateNaissance === null) {
            return null;
        }
        $now ??= new \DateTimeImmutable('today');
        $age = (int) $now->format('Y') - (int) $this->dateNaissance->format('Y');

        return match (true) {
            $age <= 7 => 'Mini-poussin',
            $age <= 9 => 'Poussin',
            $age <= 11 => 'Pupille',
            $age <= 13 => 'Benjamin',
            $age <= 15 => 'Minime',
            $age <= 17 => 'Cadet',
            $age <= 19 => 'Junior',
            $age <= 39 => 'Senior',
            $age <= 44 => 'Vétéran 1',
            $age <= 49 => 'Vétéran 2',
            $age <= 54 => 'Vétéran 3',
            $age <= 59 => 'Vétéran 4',
            $age <= 64 => 'Vétéran 5',
            $age <= 69 => 'Vétéran 6',
            $age <= 74 => 'Vétéran 7',
            $age <= 79 => 'Vétéran 8',
            default   => 'Vétéran 9+',
        };
    }

    /**
     * Indique si l'adhérent est dirigeant (vient du typeLicence FFTRi importé).
     * Pratique pour les règles d'affichage côté mobile et admin.
     */
    public function isDirigeant(): bool
    {
        return $this->typeLicence === 'Dirigeant';
    }

    /** Label "Non licencié" pour les comptes externes ou sans n° de licence. */
    public function getLicenceLabel(): string
    {
        return $this->numLicence ?? 'Non licencié';
    }

    public function getStatutLicence(): string
    {
        return $this->statutLicence;
    }

    public function setStatutLicence(string $statutLicence): self
    {
        $this->statutLicence = $statutLicence;
        return $this;
    }

    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $d): self { $this->dateNaissance = $d; return $this; }

    public function getSexe(): ?string { return $this->sexe; }
    public function setSexe(?string $s): self
    {
        $this->sexe = $s === null ? null : mb_strtolower(trim($s), 'UTF-8');
        return $this;
    }

    public function getAdresse(): ?string { return $this->adresse; }
    public function setAdresse(?string $a): self { $this->adresse = $a; return $this; }

    public function getTypeLicence(): ?string { return $this->typeLicence; }
    public function setTypeLicence(?string $t): self { $this->typeLicence = $t; return $this; }

    public function getCategorieAge(): ?string { return $this->categorieAge; }
    public function setCategorieAge(?string $c): self { $this->categorieAge = $c; return $this; }

    /** @return list<string> */
    public function getProfiles(): array
    {
        return array_values($this->profiles);
    }

    /** @return list<Profile> */
    public function getProfileEnums(): array
    {
        return array_values(array_filter(
            array_map(fn (string $v) => Profile::tryFrom($v), $this->profiles),
        ));
    }

    /**
     * Remplace la liste des profils. Garde une seule valeur entre Jeune et
     * Senior (ces deux profils sont mutuellement exclusifs).
     *
     * @param list<string|Profile> $profiles
     */
    public function setProfiles(array $profiles): self
    {
        $normalized = [];
        foreach ($profiles as $p) {
            $value = $p instanceof Profile ? $p->value : (string) $p;
            if (Profile::tryFrom($value) !== null) {
                $normalized[$value] = true;
            }
        }
        if (isset($normalized[Profile::Jeune->value]) && isset($normalized[Profile::Senior->value])) {
            unset($normalized[Profile::Senior->value]);
        }
        // Encadrant et Entraîneur sont mutuellement exclusifs (un adhérent
        // ne peut être que l'un OU l'autre). On rejette pour forcer l'admin
        // à choisir explicitement.
        if (isset($normalized[Profile::Encadrant->value]) && isset($normalized[Profile::Entraineur->value])) {
            throw new \InvalidArgumentException(
                'Un utilisateur ne peut pas être à la fois Encadrant et Entraîneur. Choisissez l\'un des deux.'
            );
        }
        $this->profiles = array_keys($normalized);
        return $this;
    }

    public function hasProfile(Profile|string $profile): bool
    {
        $value = $profile instanceof Profile ? $profile->value : $profile;
        return in_array($value, $this->profiles, true);
    }

    public function isJeune(): bool { return $this->hasProfile(Profile::Jeune); }
    public function isSenior(): bool { return $this->hasProfile(Profile::Senior); }
    public function isU25(): bool { return $this->hasProfile(Profile::U25); }
    public function isParent(): bool { return $this->hasProfile(Profile::Parent); }
    public function isEntraineur(): bool { return $this->hasProfile(Profile::Entraineur); }
    public function isEncadrant(): bool { return $this->hasProfile(Profile::Encadrant); }

    /** @return Collection<int, User> */
    public function getChildren(): Collection { return $this->children; }

    public function addChild(User $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
        }
        return $this;
    }

    public function removeChild(User $child): self
    {
        $this->children->removeElement($child);
        return $this;
    }

    /** @return Collection<int, User> */
    public function getParents(): Collection { return $this->parents; }

    public function getLinkedToUser(): ?User { return $this->linkedToUser; }
    public function setLinkedToUser(?User $u): self { $this->linkedToUser = $u; return $this; }

    /** Retourne le user "racine" pour les e-mails partagés (lui-même si primaire). */
    public function getPrimaryUser(): User
    {
        return $this->linkedToUser ?? $this;
    }

    public function isPrimary(): bool
    {
        return $this->linkedToUser === null;
    }

    /** @return Collection<int, User> */
    public function getDependents(): Collection
    {
        return $this->dependents;
    }

    /**
     * Résumé textuel pour affichage admin :
     *  - "→ Dupont Paul (A123)" si ce user est lié à un primaire
     *  - "Compte principal · 2 dépendants" si primaire avec dépendants
     *  - "" si simple user sans liens
     */
    public function getLinkLabel(): string
    {
        if ($this->linkedToUser !== null) {
            return sprintf('→ %s (%s)',
                $this->linkedToUser->getFullName(),
                $this->linkedToUser->getNumLicence(),
            );
        }
        $n = $this->dependents->count();
        if ($n > 0) {
            return sprintf('Compte principal · %d dépendant%s', $n, $n > 1 ? 's' : '');
        }
        return '';
    }

    public const ROLE_USER = 'user';
    public const ROLE_EDITEUR = 'editeur';
    public const ROLE_ENTRAINEUR = 'entraineur';
    public const ROLE_ADMIN = 'admin';
    public const ALLOWED_ROLES = [
        self::ROLE_USER,
        self::ROLE_EDITEUR,
        self::ROLE_ENTRAINEUR,
        self::ROLE_ADMIN,
    ];

    /**
     * Symfony Security : on dérive les rôles depuis le champ $role.
     * La hiérarchie (security.yaml) propage : ROLE_ADMIN > ROLE_ENTRAINEUR
     * > ROLE_EDITEUR > ROLE_USER. On renvoie ROLE_USER + le tier courant.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        $roles[] = match ($this->role) {
            self::ROLE_ADMIN => 'ROLE_ADMIN',
            self::ROLE_ENTRAINEUR => 'ROLE_ENTRAINEUR',
            self::ROLE_EDITEUR => 'ROLE_EDITEUR',
            default => 'ROLE_USER',
        };
        return array_values(array_unique($roles));
    }

    public function getRole(): string { return $this->role; }

    public function setRole(string $role): self
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            $role = self::ROLE_USER;
        }
        $this->role = $role;
        return $this;
    }

    /**
     * Compatibilité Symfony Form/CRUD existants qui appellent setRoles([...]).
     * On prend le plus élevé trouvé.
     *
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        if (in_array('ROLE_ADMIN', $roles, true))      return $this->setRole(self::ROLE_ADMIN);
        if (in_array('ROLE_ENTRAINEUR', $roles, true)) return $this->setRole(self::ROLE_ENTRAINEUR);
        if (in_array('ROLE_EDITEUR', $roles, true))    return $this->setRole(self::ROLE_EDITEUR);
        return $this->setRole(self::ROLE_USER);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    public function isAdmin(): bool { return $this->role === self::ROLE_ADMIN; }
    public function isEntraineurAdmin(): bool { return $this->role === self::ROLE_ENTRAINEUR; }
    public function isEditeur(): bool { return $this->role === self::ROLE_EDITEUR; }
    /** True si le user a un accès quelconque au backend. */
    public function hasBackendAccess(): bool
    {
        return in_array($this->role, [self::ROLE_EDITEUR, self::ROLE_ENTRAINEUR, self::ROLE_ADMIN], true);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getLastCsvSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastCsvSyncAt;
    }

    public function getAvatarFilename(): ?string { return $this->avatarFilename; }
    public function setAvatarFilename(?string $f): self { $this->avatarFilename = $f; return $this; }
    public function hasAvatar(): bool { return $this->avatarFilename !== null; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function getLoginCount(): int { return $this->loginCount; }

    public function isNotifyTrainingPlanEmail(): bool { return $this->notifyTrainingPlanEmail; }
    public function setNotifyTrainingPlanEmail(bool $v): self { $this->notifyTrainingPlanEmail = $v; return $this; }

    /** Appelé par les listeners de login (JWT mobile + admin form). */
    public function recordLogin(?\DateTimeImmutable $at = null): self
    {
        $this->lastLoginAt = $at ?? new \DateTimeImmutable();
        $this->loginCount++;
        return $this;
    }

    public function hasEverLoggedIn(): bool
    {
        return $this->lastLoginAt !== null;
    }

    public function setLastCsvSyncAt(?\DateTimeImmutable $at): self
    {
        $this->lastCsvSyncAt = $at;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, DeviceToken>
     */
    public function getDeviceTokens(): Collection
    {
        return $this->deviceTokens;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // no plain-text credentials held
    }

    public function __toString(): string
    {
        if (!isset($this->prenom, $this->nom)) {
            return $this->email ?? '#'.$this->id;
        }
        return $this->numLicence !== null
            ? $this->getFullName().' ('.$this->numLicence.')'
            : $this->getFullName();
    }
}
