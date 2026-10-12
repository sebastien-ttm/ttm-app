<?php

namespace App\Entity;

use App\Repository\TrainingSlotCheckInRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Présence réelle d'un adhérent à un entraînement sur inscription, émargée sur place depuis l'espace Staff de l'appli
 * (équivalent de EventCheckIn pour les événements). Distincte de l'inscription (TrainingSlotRegistration), qui n'est
 * qu'une intention : on peut être inscrit sans venir, ou venir sans s'être inscrit.
 *
 * Se rattache à l'occurrence du créneau, comme l'inscription : `template` (+ `weekStartsAt`) pour un créneau de la
 * semaine type, `slot` pour un créneau occasionnel ; exactement l'un des deux.
 */
#[ORM\Entity(repositoryClass: TrainingSlotCheckInRepository::class)]
#[ORM\Table(name: 'training_slot_check_in')]
#[ORM\UniqueConstraint(name: 'uniq_training_check_in_user_template_week', columns: ['user_id', 'template_id', 'week_starts_at'])]
#[ORM\UniqueConstraint(name: 'uniq_training_check_in_user_slot', columns: ['user_id', 'slot_id'])]
#[ORM\Index(name: 'idx_training_check_in_template_week', columns: ['template_id', 'week_starts_at'])]
#[ORM\Index(name: 'idx_training_check_in_slot', columns: ['slot_id'])]
class TrainingSlotCheckIn
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: TrainingSlotTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TrainingSlotTemplate $template;

    #[ORM\ManyToOne(targetEntity: TrainingSlot::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TrainingSlot $slot;

    /** Lundi de la semaine concernée. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $weekStartsAt;

    /** Personne qui a émargé. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $checkedBy;

    #[ORM\Column]
    private \DateTimeImmutable $checkedAt;

    public function __construct(User $user, ?TrainingSlotTemplate $template, ?TrainingSlot $slot, \DateTimeImmutable $weekStartsAt, ?User $checkedBy)
    {
        if (($template === null) === ($slot === null)) {
            throw new \InvalidArgumentException('Un émargement porte sur un créneau de la semaine type OU sur un créneau occasionnel.');
        }
        $this->user = $user;
        $this->template = $template;
        $this->slot = $slot;
        $this->weekStartsAt = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $this->checkedBy = $checkedBy;
        $this->checkedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getTemplate(): ?TrainingSlotTemplate { return $this->template; }
    public function getSlot(): ?TrainingSlot { return $this->slot; }
    public function getWeekStartsAt(): \DateTimeImmutable { return $this->weekStartsAt; }
    public function getCheckedBy(): ?User { return $this->checkedBy; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
}
