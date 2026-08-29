<?php

namespace App\Entity;

use App\Repository\GouterSignupRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Positionnement d'un parent/jeune pour amener le goûter un mercredi donné.
 * Capacité : 2 personnes par date (contrainte API — pas au niveau DB pour
 * laisser à l'admin la possibilité de dépasser exceptionnellement).
 *
 * L'entité ne persiste que les positionnements — les créneaux (dates de
 * mercredi) sont virtuels et générés à la volée par l'API selon la plage
 * demandée.
 */
#[ORM\Entity(repositoryClass: GouterSignupRepository::class)]
#[ORM\Table(name: 'gouter_signup')]
#[ORM\UniqueConstraint(name: 'uniq_gouter_date_user', columns: ['gouter_date', 'user_id'])]
#[ORM\Index(name: 'idx_gouter_date', columns: ['gouter_date'])]
class GouterSignup
{
    /** Capacité par créneau — 2 personnes pour partager la charge. */
    public const CAPACITY_PER_SLOT = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Date du mercredi ciblé (heure ignorée). */
    #[ORM\Column(name: 'gouter_date', type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Auteur du positionnement — NULL si self-signup depuis le mobile,
     * référence un admin sinon (positionnement manuel depuis le backend).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct(\DateTimeImmutable $date, User $user, ?User $createdBy = null)
    {
        // Normalise à date pure (00:00:00 UTC pour éviter les glissements TZ)
        $this->date = $date->setTime(0, 0, 0);
        $this->user = $user;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getUser(): User { return $this->user; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
}
