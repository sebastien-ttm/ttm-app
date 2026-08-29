<?php

namespace App\Entity;

use App\Repository\StaffWeekUnavailabilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Déclaration explicite d'indisponibilité d'un membre du staff (encadrant
 * ou entraîneur) sur une semaine entière. Sert à distinguer trois états
 * dans la supervision :
 *
 *   - Positionné sur au moins un créneau → présent, dispo
 *   - StaffWeekUnavailability posé      → non dispo (déclaré)
 *   - Ni l'un ni l'autre                → aucune réponse (silence)
 *
 * Ne supprime PAS automatiquement les StaffPresence existantes — si un
 * encadrant se marque non-dispo alors qu'il avait déjà réservé un
 * créneau, l'UI le signale comme conflit à l'admin.
 */
#[ORM\Entity(repositoryClass: StaffWeekUnavailabilityRepository::class)]
#[ORM\Table(name: 'staff_week_unavailability')]
#[ORM\UniqueConstraint(name: 'uniq_staff_week_unav_user_week', columns: ['user_id', 'week_starts_at'])]
#[ORM\Index(name: 'idx_staff_week_unav_week', columns: ['week_starts_at'])]
class StaffWeekUnavailability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Lundi de la semaine ciblée (toujours snappé au lundi). */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $weekStartsAt;

    /** Note optionnelle : « en déplacement pro », « vacances », … */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, \DateTimeImmutable $weekStartsAt, ?string $notes = null)
    {
        $this->user = $user;
        $this->weekStartsAt = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $this->notes = $notes !== null ? (trim($notes) ?: null) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getWeekStartsAt(): \DateTimeImmutable { return $this->weekStartsAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n !== null ? (trim($n) ?: null) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
