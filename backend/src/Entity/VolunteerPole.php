<?php

namespace App\Entity;

use App\Repository\VolunteerPoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pôle d'une campagne de bénévolat : regroupe un domaine de l'organisation (courses, sécurité, site…). Ses
 * responsables (`managers`, comptes adhérents) valident les candidatures sur les fiches du pôle depuis l'appli.
 * `managerNames` est l'affichage libre des responsables (ex. « Pauline & Anne »), utile tant que tous n'ont pas
 * de compte rattaché.
 */
#[ORM\Entity(repositoryClass: VolunteerPoleRepository::class)]
#[ORM\Table(name: 'volunteer_pole')]
#[ORM\UniqueConstraint(name: 'uniq_volunteer_pole_campaign_name', columns: ['campaign_id', 'name'])]
class VolunteerPole
{
    /** Couleurs d'accent proposées (charte de l'événement). */
    public const COLORS = ['teal', 'sun', 'coral', 'orange', 'navy'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VolunteerCampaign::class, inversedBy: 'poles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VolunteerCampaign $campaign;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $managerNames = null;

    #[ORM\Column(length: 16, options: ['default' => 'teal'])]
    private string $color = 'teal';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'volunteer_pole_manager')]
    #[ORM\JoinColumn(name: 'pole_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $managers;

    /** @var Collection<int, VolunteerRole> */
    #[ORM\OneToMany(targetEntity: VolunteerRole::class, mappedBy: 'pole', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $roles;

    public function __construct(VolunteerCampaign $campaign, string $name = '')
    {
        $this->campaign = $campaign;
        $this->name = trim($name);
        $this->managers = new ArrayCollection();
        $this->roles = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getCampaign(): VolunteerCampaign { return $this->campaign; }
    public function setCampaign(VolunteerCampaign $c): self { $this->campaign = $c; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getManagerNames(): ?string { return $this->managerNames; }
    public function setManagerNames(?string $n): self { $this->managerNames = $n !== null && trim($n) !== '' ? trim($n) : null; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): self
    {
        $this->color = in_array($color, self::COLORS, true) ? $color : 'teal';
        return $this;
    }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    /** @return Collection<int, User> */
    public function getManagers(): Collection { return $this->managers; }
    public function addManager(User $u): self { if (!$this->managers->contains($u)) { $this->managers->add($u); } return $this; }
    public function removeManager(User $u): self { $this->managers->removeElement($u); return $this; }

    public function isManagedBy(User $u): bool
    {
        foreach ($this->managers as $m) {
            if ($m->getId() === $u->getId()) {
                return true;
            }
        }
        return false;
    }

    /** @return Collection<int, VolunteerRole> */
    public function getRoles(): Collection { return $this->roles; }

    public function addRole(VolunteerRole $role): self
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : '#'.$this->id;
    }
}
