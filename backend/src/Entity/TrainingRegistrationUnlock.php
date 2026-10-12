<?php

namespace App\Entity;

use App\Repository\TrainingRegistrationUnlockRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Semaine « débloquée » par un entraîneur : chaque adhérent peut alors s'inscrire à DEUX entraînements sur
 * inscription (au lieu d'un seul), tant qu'il reste des places. Pas de ligne = règle normale, une seule inscription.
 * Posée / retirée depuis la page des créneaux d'entraînement de la semaine (backend).
 */
#[ORM\Entity(repositoryClass: TrainingRegistrationUnlockRepository::class)]
#[ORM\Table(name: 'training_registration_unlock')]
#[ORM\UniqueConstraint(name: 'uniq_training_registration_unlock_week', columns: ['week_starts_at'])]
class TrainingRegistrationUnlock
{
    /** Nombre d'inscriptions par adhérent et par semaine quand la semaine est débloquée. */
    public const UNLOCKED_LIMIT = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Lundi de la semaine débloquée. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $weekStartsAt;

    /** Entraîneur qui a débloqué la semaine. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $unlockedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $weekStartsAt, ?User $unlockedBy)
    {
        $this->weekStartsAt = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $this->unlockedBy = $unlockedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getWeekStartsAt(): \DateTimeImmutable { return $this->weekStartsAt; }
    public function getUnlockedBy(): ?User { return $this->unlockedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
