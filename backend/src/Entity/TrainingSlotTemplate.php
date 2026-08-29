<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
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
    use AudienceAwareTrait;

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
    private string $title = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $location = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Désactivable sans suppression — n'apparaît plus dans la semaine type. */
    #[ORM\Column]
    private bool $isActive = true;

    /** Ordre d'affichage à heure égale (par défaut on trie par jour+heure). */
    #[ORM\Column(type: 'smallint')]
    private int $position = 0;

    /**
     * Optionnel : si défini, le créneau ne s'applique qu'à partir de cette date.
     * Sert pour les créneaux qui ne démarrent qu'en cours de saison
     * (ex. cours de PPG à partir de janvier).
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    /** Optionnel : dernier jour d'application (inclus). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    /**
     * Saison de rattachement. Null = template legacy / permanent
     * (peut être migré manuellement vers une saison spécifique).
     * Sert de filtre principal dans le CRUD admin — les startsAt/endsAt
     * restent utilisables pour sur-restreindre dans la saison (ex. PPG
     * qui démarre en janvier au milieu d'une saison sept→juin).
     */
    #[ORM\ManyToOne(targetEntity: TrainingSeason::class)]
    #[ORM\JoinColumn(name: 'season_id', nullable: true, onDelete: 'SET NULL')]
    private ?TrainingSeason $season = null;

    public function __construct()
    {
        $this->startTime = new \DateTimeImmutable('18:30:00');
    }

    /**
     * Duplique le template vers une nouvelle saison, avec plage de dates
     * optionnelle. Utilisé lors du clonage de semaine type — l'ancien
     * garde ses dates (généralement bornées par le clonage), le nouveau
     * démarre à la nouvelle saison. Copie tous les champs métier mais
     * PAS l'id ni les startsAt/endsAt (à définir par le caller).
     */
    public function duplicateForSeason(
        ?TrainingSeason $season,
        ?\DateTimeImmutable $startsAt = null,
        ?\DateTimeImmutable $endsAt = null,
    ): self {
        $copy = new self();
        $copy->setDayOfWeek($this->dayOfWeek);
        $copy->setStartTime($this->startTime);
        $copy->setDurationMinutes($this->durationMinutes);
        $copy->setSport($this->sport);
        $copy->setTitle($this->title);
        $copy->setLocation($this->location);
        $copy->setDescription($this->description);
        $copy->setIsActive($this->isActive);
        $copy->setPosition($this->position);
        $copy->setAudience($this->getAudience());
        $copy->setStartsAt($startsAt);
        $copy->setEndsAt($endsAt);
        $copy->setSeason($season);
        return $copy;
    }

    /** Alias rétro-compat de duplicateForSeason(null, $startsAt, $endsAt). */
    public function duplicateForRange(?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): self
    {
        return $this->duplicateForSeason(null, $startsAt, $endsAt);
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

    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $d): self { $this->startsAt = $d; return $this; }

    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): self { $this->endsAt = $d; return $this; }

    public function getSeason(): ?TrainingSeason { return $this->season; }
    public function setSeason(?TrainingSeason $s): self { $this->season = $s; return $this; }

    /** Renvoie true si ce créneau s'applique au lundi donné. */
    public function appliesOn(\DateTimeImmutable $monday): bool
    {
        if ($this->startsAt !== null && $monday < $this->startsAt->modify('monday this week')->setTime(0, 0, 0)) {
            return false;
        }
        if ($this->endsAt !== null && $monday > $this->endsAt->modify('monday this week')->setTime(0, 0, 0)) {
            return false;
        }
        return true;
    }

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
