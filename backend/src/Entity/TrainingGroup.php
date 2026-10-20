<?php

namespace App\Entity;

use App\Repository\TrainingGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Groupe d'entraînement « à la volée », créé depuis l'espace Staff par un encadrant / entraîneur juste avant une
 * séance. Éphémère : il reste valable jusqu'au prochain créneau (`expiresAt`, voir TrainingGroupService::plan()) ou
 * jusqu'à ce qu'un encadrant le termine (`closedAt`).
 *
 * Un encadrant / entraîneur ne peut avoir qu'un seul groupe actif à la fois. Le groupe peut être partagé avec d'autres
 * encadrants (`coaches`, qui le gèrent ensemble) ; les adhérents ajoutés (`members`) le voient dans leur onglet
 * Entraînement, avec les informations, la trace GPX, les commentaires et la position en direct des encadrants.
 */
#[ORM\Entity(repositoryClass: TrainingGroupRepository::class)]
#[ORM\Table(name: 'training_group')]
#[ORM\Index(name: 'idx_training_group_expires', columns: ['expires_at'])]
class TrainingGroup
{
    public const NAME_MAX = 80;
    public const INFO_MAX = 3000;
    public const MAX_MEMBERS = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** L'encadrant qui a créé le groupe (les autres encadrants le gèrent avec lui via `coaches`). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(length: self::NAME_MAX, nullable: true)]
    private ?string $name = null;

    /** Informations pour le groupe (lieu de rendez-vous, programme de la séance…). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $info = null;

    /** Séance de référence au moment de la création (affichage seulement). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $sessionTitle = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sessionStartsAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Fin de validité : début du prochain créneau après la séance de référence. */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** Terminé à la main par un encadrant (ou plus aucun encadrant) ; null = pas terminé. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'training_group_coach')]
    #[ORM\JoinColumn(name: 'training_group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $coaches;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'training_group_member')]
    #[ORM\JoinColumn(name: 'training_group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $members;

    public function __construct(User $createdBy, \DateTimeImmutable $expiresAt)
    {
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->coaches = new ArrayCollection();
        $this->members = new ArrayCollection();
        $this->coaches->add($createdBy);
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedBy(): User { return $this->createdBy; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self
    {
        $name = $name !== null ? trim($name) : '';
        $this->name = $name === '' ? null : mb_substr($name, 0, self::NAME_MAX);
        return $this;
    }

    /** Nom affiché : le nom choisi, à défaut « Groupe de {prénom de l'encadrant} ». */
    public function displayName(): string
    {
        return $this->name ?? 'Groupe de '.$this->createdBy->getPrenom();
    }

    public function getInfo(): ?string { return $this->info; }
    public function setInfo(?string $info): self
    {
        $info = $info !== null ? trim($info) : '';
        $this->info = $info === '' ? null : mb_substr($info, 0, self::INFO_MAX);
        return $this;
    }

    public function getSessionTitle(): ?string { return $this->sessionTitle; }
    public function getSessionStartsAt(): ?\DateTimeImmutable { return $this->sessionStartsAt; }
    public function setSession(?string $title, ?\DateTimeImmutable $startsAt): self
    {
        $this->sessionTitle = $title !== null ? mb_substr($title, 0, 160) : null;
        $this->sessionStartsAt = $startsAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }

    public function close(): self
    {
        $this->closedAt ??= new \DateTimeImmutable();
        return $this;
    }

    /** Ni terminé, ni expiré. */
    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return $this->closedAt === null && $this->expiresAt > ($now ?? new \DateTimeImmutable());
    }

    /** @return Collection<int, User> */
    public function getCoaches(): Collection { return $this->coaches; }

    /** @return Collection<int, User> */
    public function getMembers(): Collection { return $this->members; }

    public function hasCoach(User $user): bool
    {
        foreach ($this->coaches as $c) {
            if ($c->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    public function hasMember(User $user): bool
    {
        foreach ($this->members as $m) {
            if ($m->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    public function addCoach(User $user): self
    {
        if (!$this->hasCoach($user)) {
            $this->coaches->add($user);
        }
        return $this;
    }

    public function removeCoach(User $user): self
    {
        foreach ($this->coaches as $c) {
            if ($c->getId() === $user->getId()) {
                $this->coaches->removeElement($c);
            }
        }
        return $this;
    }

    public function addMember(User $user): self
    {
        if (!$this->hasMember($user)) {
            $this->members->add($user);
        }
        return $this;
    }

    public function removeMember(User $user): self
    {
        foreach ($this->members as $m) {
            if ($m->getId() === $user->getId()) {
                $this->members->removeElement($m);
            }
        }
        return $this;
    }

    public function __toString(): string { return $this->displayName(); }
}
