<?php

namespace App\Entity;

use App\Repository\VolunteerApplicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Positionnement d'un adhérent sur une fiche de bénévolat. Un adhérent peut se positionner sur plusieurs fiches,
 * une seule fois par fiche. Cycle : en attente → validée ou déclinée par un responsable du pôle (ou un admin).
 * `assignedBy` est renseigné quand un responsable a affecté l'adhérent directement (candidature créée validée).
 */
#[ORM\Entity(repositoryClass: VolunteerApplicationRepository::class)]
#[ORM\Table(name: 'volunteer_application')]
#[ORM\UniqueConstraint(name: 'uniq_volunteer_application_role_user', columns: ['role_id', 'user_id'])]
#[ORM\Index(name: 'idx_volunteer_application_status', columns: ['status'])]
class VolunteerApplication
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_DECLINED];

    public const MESSAGE_MAX = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VolunteerRole::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VolunteerRole $role;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    /** Mot de l'adhérent : disponibilités, expérience… */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: self::MESSAGE_MAX)]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedBy = null;

    public function __construct(VolunteerRole $role, User $user, ?string $message = null)
    {
        $this->role = $role;
        $this->user = $user;
        $this->setMessage($message);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRole(): VolunteerRole { return $this->role; }
    public function getUser(): User { return $this->user; }

    public function getStatus(): string { return $this->status; }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACCEPTED => 'Validée',
            self::STATUS_DECLINED => 'Déclinée',
            default => 'En attente',
        };
    }

    /** Décision d'un responsable (ou d'un admin) : validée ou déclinée. */
    public function decide(string $status, ?User $by): self
    {
        $this->status = in_array($status, self::STATUSES, true) ? $status : self::STATUS_PENDING;
        $this->decidedAt = $this->status === self::STATUS_PENDING ? null : new \DateTimeImmutable();
        $this->decidedBy = $this->status === self::STATUS_PENDING ? null : $by;
        return $this;
    }

    /** Nouvelle candidature après un refus : repasse en attente. */
    public function reopen(?string $message): self
    {
        $this->status = self::STATUS_PENDING;
        $this->decidedAt = null;
        $this->decidedBy = null;
        $this->assignedBy = null;
        $this->setMessage($message);
        $this->createdAt = new \DateTimeImmutable();
        return $this;
    }

    /** Édition admin du statut (sans passer par decide() côté formulaire). */
    public function setStatus(string $status): self
    {
        return $this->decide($status, $this->decidedBy);
    }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self
    {
        $message = $message !== null ? trim($message) : null;
        $this->message = $message !== null && $message !== '' ? mb_substr($message, 0, self::MESSAGE_MAX) : null;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecidedBy(): ?User { return $this->decidedBy; }

    public function getAssignedBy(): ?User { return $this->assignedBy; }
    public function setAssignedBy(?User $u): self { $this->assignedBy = $u; return $this; }

    /** Libellés admin. */
    public function getPoleName(): string { return $this->role->getPole()->getName(); }
    public function getRoleLabel(): string { return (string) $this->role; }
}
