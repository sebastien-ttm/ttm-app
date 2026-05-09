<?php

namespace App\Entity;

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
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
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
}
