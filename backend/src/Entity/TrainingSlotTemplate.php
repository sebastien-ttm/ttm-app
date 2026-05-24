<?php

namespace App\Entity;

use App\Enum\Sport;
use App\Repository\TrainingSlotTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un créneau de la "semaine type" du club, défini par les entraîneurs.
 * Sert de base à la génération des créneaux affichés chaque semaine ;
 * peut être annulé ou modifié pour une semaine donnée via TrainingSlot.
 */
#[ORM\Entity(repositoryClass: TrainingSlotTemplateRepository::class)]
#[ORM\Table(name: 'training_slot_template')]
class TrainingSlotTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Lundi = 1, ... Dimanche = 7 (ISO-8601). */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 7)]
    private int $dayOfWeek = 1;

    /** Heure de début, ex. "18:30:00". */
    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 5, max: 600)]
    private int $durationMinutes = 60;

    #[ORM\Column(length: 16, enumType: Sport::class)]
    private Sport $sport = Sport::Natation;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $title;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $location;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Désactivable sans suppression — n'apparaît plus dans la semaine type. */
    #[ORM\Column]
    private bool $isActive = true;

    /** Ordre d'affichage à heure égale (par défaut on trie par jour+heure). */
    #[ORM\Column(type: 'smallint')]
    private int $position = 0;

    public function __construct()
    {
        $this->startTime = new \DateTimeImmutable('18:30:00');
    }

    public function getId(): ?int { return $this->id; }

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

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $b): self { $this->isActive = $b; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    public function __toString(): string
    {
        $jours = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        return sprintf(
            '%s %s — %s (%s)',
            $jours[$this->dayOfWeek] ?? '?',
            $this->startTime->format('H:i'),
            $this->title ?? '?',
            $this->sport->label(),
        );
    }
}
