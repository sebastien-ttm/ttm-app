<?php

namespace App\Entity;

use App\Repository\TrainingSeasonRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration globale de la saison d'entraînement.
 *
 * Singleton de facto : on n'a jamais qu'UNE saison "courante" à la fois
 * (le repo expose `findOrCreate()`). Les créneaux de la semaine type ne
 * sont projetés sur une semaine que si cette semaine est dans la saison.
 *
 * Les dates sont nullables : si les deux sont vides, aucune restriction
 * de saison n'est appliquée (la semaine type s'applique toute l'année).
 */
#[ORM\Entity(repositoryClass: TrainingSeasonRepository::class)]
#[ORM\Table(name: 'training_season')]
class TrainingSeason
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Premier lundi de la saison (inclus). Null = pas de limite basse. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    /** Dernier dimanche de la saison (inclus). Null = pas de limite haute. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    public function getId(): ?int { return $this->id; }

    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $d): self { $this->startsAt = $d; return $this; }

    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): self { $this->endsAt = $d; return $this; }

    /** Renvoie true si la date est dans la saison (ou si pas de saison définie). */
    public function contains(\DateTimeImmutable $date): bool
    {
        if ($this->startsAt !== null && $date < $this->startsAt) {
            return false;
        }
        if ($this->endsAt !== null && $date > $this->endsAt) {
            return false;
        }
        return true;
    }

    public function isEmpty(): bool
    {
        return $this->startsAt === null && $this->endsAt === null;
    }
}
