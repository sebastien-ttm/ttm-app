<?php

namespace App\Entity;

use App\Enum\StaffAbsenceReason;
use App\Repository\StaffDayUnavailabilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Déclaration explicite d'indisponibilité d'un membre du staff (encadrant
 * ou entraîneur) sur une SEULE journée, avec un motif obligatoire
 * (maladie / vacances / déplacement) — contrairement à
 * StaffWeekUnavailability (semaine entière), plus adapté à une absence
 * ponctuelle d'un jour.
 *
 * Comme pour StaffWeekUnavailability, ne supprime pas automatiquement
 * les StaffPresence existantes en cas de conflit : voir
 * StaffPresenceService::setDayUnavailable() pour la cascade appliquée
 * (pose 'unavailable' sur les créneaux non annulés de la journée).
 */
#[ORM\Entity(repositoryClass: StaffDayUnavailabilityRepository::class)]
#[ORM\Table(name: 'staff_day_unavailability')]
#[ORM\UniqueConstraint(name: 'uniq_staff_day_unav_user_date', columns: ['user_id', 'date'])]
#[ORM\Index(name: 'idx_staff_day_unav_date', columns: ['date'])]
class StaffDayUnavailability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 20, nullable: true, enumType: StaffAbsenceReason::class)]
    private ?StaffAbsenceReason $reason = null;

    /** Note libre optionnelle, en complément du motif. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        \DateTimeImmutable $date,
        ?StaffAbsenceReason $reason = null,
        ?string $notes = null,
    ) {
        $this->user = $user;
        $this->date = $date->setTime(0, 0, 0);
        $this->reason = $reason;
        $this->notes = $notes !== null ? (trim($notes) ?: null) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getReason(): ?StaffAbsenceReason { return $this->reason; }
    public function setReason(?StaffAbsenceReason $r): self { $this->reason = $r; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n !== null ? (trim($n) ?: null) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
