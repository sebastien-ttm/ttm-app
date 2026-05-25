<?php

namespace App\Entity;

use App\Enum\Profile;
use App\Enum\UserCategory;
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

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    private string $numLicence;

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

    #[ORM\Column(length: 16, enumType: UserCategory::class)]
    private UserCategory $categorie = UserCategory::Senior;

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
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCsvSyncAt = null;

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

    public function getNumLicence(): string
    {
        return $this->numLicence;
    }

    public function setNumLicence(string $numLicence): self
    {
        $this->numLicence = $numLicence;
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

    public function getCategorie(): UserCategory
    {
        return $this->categorie;
    }

    public function setCategorie(UserCategory $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
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
     * Senior (ces deux profils sont mutuellement exclusifs). Synchronise
     * automatiquement la categorie et les rôles dérivés (ROLE_ENCADRANT).
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
        // Jeune & Senior mutuellement exclusifs : si les deux sont là, on garde Jeune
        // (cas peu probable mais on protège).
        if (isset($normalized[Profile::Jeune->value]) && isset($normalized[Profile::Senior->value])) {
            unset($normalized[Profile::Senior->value]);
        }
        $this->profiles = array_keys($normalized);

        // Sync categorie (pour rétrocompat avec le code qui lit categorie directement)
        if (in_array(Profile::Jeune->value, $this->profiles, true)) {
            $this->categorie = UserCategory::Jeune;
        } elseif (in_array(Profile::Senior->value, $this->profiles, true)) {
            $this->categorie = UserCategory::Senior;
        }

        // Sync ROLE_ENCADRANT depuis le profil encadrant
        $rolesWithoutEncadrant = array_values(array_filter(
            $this->roles,
            fn (string $r) => $r !== 'ROLE_ENCADRANT',
        ));
        if (in_array(Profile::Encadrant->value, $this->profiles, true)) {
            $rolesWithoutEncadrant[] = 'ROLE_ENCADRANT';
        }
        $this->roles = array_values(array_unique($rolesWithoutEncadrant));

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
    public function isEncadrant(): bool { return $this->hasProfile(Profile::Encadrant); }

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

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
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
        return $this->getFullName().' ('.$this->numLicence.')';
    }
}
