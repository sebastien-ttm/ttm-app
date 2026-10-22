<?php

namespace App\Entity;

use App\Repository\TrainingSlotRegistrationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inscription d'un adhérent à un entraînement sur inscription, pour UNE semaine.
 *
 * Elle se rattache à l'occurrence du créneau cette semaine-là :
 *  - créneau de la semaine type → `template` (+ `weekStartsAt`) : pas besoin que la semaine soit matérialisée ;
 *  - créneau occasionnel → `slot`.
 * Exactement l'un des deux est renseigné.
 *
 * Règle « une seule inscription par semaine et par adhérent, tous créneaux sur inscription confondus » (deux quand un
 * entraîneur a débloqué la semaine : TrainingRegistrationUnlock) : chaque inscription d'une semaine prend un rang
 * (`position` 1 ou 2), et l'unicité (user, semaine, rang) l'impose en base même en cas de requêtes simultanées. Un
 * adhérent ne peut pas non plus s'inscrire deux fois au même créneau (unicités par créneau).
 */
#[ORM\Entity(repositoryClass: TrainingSlotRegistrationRepository::class)]
#[ORM\Table(name: 'training_slot_registration')]
#[ORM\UniqueConstraint(name: 'uniq_training_registration_user_week_position', columns: ['user_id', 'week_starts_at', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_training_registration_user_template_week', columns: ['user_id', 'template_id', 'week_starts_at'])]
#[ORM\UniqueConstraint(name: 'uniq_training_registration_user_slot', columns: ['user_id', 'slot_id'])]
#[ORM\Index(name: 'idx_training_registration_template_week', columns: ['template_id', 'week_starts_at'])]
#[ORM\Index(name: 'idx_training_registration_slot', columns: ['slot_id'])]
class TrainingSlotRegistration
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

    /**
     * Rang de l'inscription dans la semaine pour cet adhérent : 1, ou 2 (semaine débloquée). Une inscription à un
     * entraînement de stage, dispensée de la limite hebdomadaire, prend un rang propre à son créneau (3 et plus).
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $position;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ?TrainingSlotTemplate $template, ?TrainingSlot $slot, \DateTimeImmutable $weekStartsAt, int $position = 1)
    {
        if (($template === null) === ($slot === null)) {
            throw new \InvalidArgumentException('Une inscription porte sur un créneau de la semaine type OU sur un créneau occasionnel.');
        }
        $this->user = $user;
        $this->template = $template;
        $this->slot = $slot;
        $this->weekStartsAt = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getTemplate(): ?TrainingSlotTemplate { return $this->template; }
    public function getSlot(): ?TrainingSlot { return $this->slot; }
    public function getWeekStartsAt(): \DateTimeImmutable { return $this->weekStartsAt; }
    public function getPosition(): int { return $this->position; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
