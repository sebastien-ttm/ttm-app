<?php

namespace App\Entity;

use App\Enum\Sport;
use App\Repository\TrainingSlotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Créneau d'entraînement pour une semaine PRÉCISE.
 *
 * Trois usages :
 *  1) Override d'un créneau récurrent : template != null, isCancelled = false,
 *     les champs peuvent différer du template pour cette semaine seulement.
 *  2) Annulation d'un créneau récurrent : template != null, isCancelled = true.
 *  3) Créneau occasionnel (vacances, événement) : template = null.
 *
 * Indexé sur (weekStartsAt) pour le rendu d'une semaine.
 * Une contrainte unique (weekStartsAt, template_id) garantit qu'un template
 * n'a qu'un seul override par semaine (les créneaux occasionnels n'ont pas
 * de template, et plusieurs peuvent coexister sur la même semaine).
 */
#[ORM\Entity(repositoryClass: TrainingSlotRepository::class)]
#[ORM\Table(name: 'training_slot')]
#[ORM\Index(name: 'idx_training_slot_week', columns: ['week_starts_at'])]
#[ORM\UniqueConstraint(name: 'uniq_training_slot_week_template', columns: ['week_starts_at', 'template_id'])]
class TrainingSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Lundi de la semaine concernée (toujours snappé au lundi). */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $weekStartsAt;

    /** Lundi = 1, ... Dimanche = 7. */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 7)]
    private int $dayOfWeek = 1;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 5, max: 600)]
    private int $durationMinutes = 60;

    #[ORM\Column(length: 16, enumType: Sport::class)]
    private Sport $sport = Sport::Natation;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $title = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $location = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isCancelled = false;

    /** Origine éventuelle dans la semaine type (null = créneau occasionnel). */
    #[ORM\ManyToOne(targetEntity: TrainingSlotTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TrainingSlotTemplate $template = null;

    public function __construct()
    {
        $this->weekStartsAt = new \DateTimeImmutable('monday this week');
        $this->startTime = new \DateTimeImmutable('18:30:00');
    }

    /** S'assure que weekStartsAt est bien stocké comme un lundi. */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function snapWeekStartsAtToMonday(): void
    {
        $monday = $this->weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        if ($monday->format('Y-m-d') !== $this->weekStartsAt->format('Y-m-d')) {
            $this->weekStartsAt = $monday;
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getWeekStartsAt(): \DateTimeImmutable { return $this->weekStartsAt; }
    public function setWeekStartsAt(\DateTimeImmutable $d): self { $this->weekStartsAt = $d; return $this; }

    public function getDayOfWeek(): int { return $this->dayOfWeek; }
    public function setDayOfWeek(int $d): self { $this->dayOfWeek = $d; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $t): self { $this->startTime = $t; return $this; }

    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $m): self { $this->durationMinutes = $m; return $this; }

    public function getSport(): Sport { return $this->sport; }
    public function setSport(Sport $s): self { $this->sport = $s; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): self { $this->title = $t; return $this; }

    public function getLocation(): string { return $this->location; }
    public function setLocation(string $l): self { $this->location = $l; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function isCancelled(): bool { return $this->isCancelled; }
    public function setIsCancelled(bool $b): self { $this->isCancelled = $b; return $this; }

    public function getTemplate(): ?TrainingSlotTemplate { return $this->template; }
    public function setTemplate(?TrainingSlotTemplate $t): self { $this->template = $t; return $this; }

    public function isOccasional(): bool { return $this->template === null; }

    /** Copie les valeurs courantes d'un template (pour matérialiser un override). */
    public function fillFromTemplate(TrainingSlotTemplate $tpl): self
    {
        $this->template = $tpl;
        $this->dayOfWeek = $tpl->getDayOfWeek();
        $this->startTime = $tpl->getStartTime();
        $this->durationMinutes = $tpl->getDurationMinutes();
        $this->sport = $tpl->getSport();
        $this->title = $tpl->getTitle();
        $this->location = $tpl->getLocation();
        $this->description = $tpl->getDescription();
        return $this;
    }
}
