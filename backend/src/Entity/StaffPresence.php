<?php

namespace App\Entity;

use App\Repository\StaffPresenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Présence ou tâche d'un membre du staff (encadrant / entraîneur)
 * sur une semaine donnée.
 *
 * 2 usages :
 *  1) Présence sur un créneau d'entraînement existant → slot != null,
 *     les champs title/date/startTime/durationMinutes sont une copie
 *     dénormalisée des valeurs du slot au moment de la création (utile
 *     si le slot est modifié plus tard : la présence reste valable pour
 *     les stats).
 *  2) « Créneau hors entraînement » saisi par un entraîneur (réunion,
 *     compta, déplacement administratif) → slot == null, les champs
 *     title/date/startTime/durationMinutes sont saisis manuellement.
 *
 * Statut :
 *  - 'scheduled' : la personne se positionne à l'avance
 *  - 'attended'  : la personne a confirmé sa présence (post-créneau)
 */
#[ORM\Entity(repositoryClass: StaffPresenceRepository::class)]
#[ORM\Table(name: 'staff_presence')]
#[ORM\Index(name: 'idx_staff_presence_user_week', columns: ['user_id', 'week_starts_at'])]
#[ORM\Index(name: 'idx_staff_presence_slot', columns: ['slot_id'])]
#[ORM\UniqueConstraint(name: 'uniq_staff_presence_user_slot', columns: ['user_id', 'slot_id'])]
#[ORM\HasLifecycleCallbacks]
class StaffPresence
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ATTENDED = 'attended';
    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_ATTENDED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Null pour les créneaux hors entraînement (tâches admin). */
    #[ORM\ManyToOne(targetEntity: TrainingSlot::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TrainingSlot $slot = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $title = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 5, max: 600)]
    private int $durationMinutes = 60;

    /** Lundi de la semaine (snappé automatiquement), indexé pour les requêtes hebdo. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $weekStartsAt;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_SCHEDULED;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->date = new \DateTimeImmutable('today');
        $this->startTime = new \DateTimeImmutable('18:30:00');
        $this->weekStartsAt = $this->date->modify('monday this week')->setTime(0, 0, 0);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Tient weekStartsAt et updatedAt à jour. */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncMetaFields(): void
    {
        $this->weekStartsAt = $this->date->modify('monday this week')->setTime(0, 0, 0);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }

    public function getSlot(): ?TrainingSlot { return $this->slot; }
    public function setSlot(?TrainingSlot $slot): self { $this->slot = $slot; return $this; }
    public function isCustom(): bool { return $this->slot === null; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): self { $this->title = $t; return $this; }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $d): self { $this->date = $d; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $t): self { $this->startTime = $t; return $this; }

    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $m): self { $this->durationMinutes = $m; return $this; }

    public function getWeekStartsAt(): \DateTimeImmutable { return $this->weekStartsAt; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self
    {
        if (!in_array($s, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Status invalide : {$s}");
        }
        $this->status = $s;
        return $this;
    }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Recopie les champs d'un slot dans la présence. */
    public function fillFromSlot(TrainingSlot $slot): self
    {
        $this->slot = $slot;
        $this->title = $slot->getTitle();
        $monday = $slot->getWeekStartsAt();
        $this->date = $monday->modify(sprintf('+%d days', $slot->getDayOfWeek() - 1));
        $this->startTime = $slot->getStartTime();
        $this->durationMinutes = $slot->getDurationMinutes();
        return $this;
    }
}
