<?php

namespace App\Entity;

use App\Repository\TrainingGroupPositionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dernière position GPS partagée en direct par un encadrant d'un groupe d'entraînement : une ligne par (groupe,
 * encadrant), mise à jour à chaque envoi par l'appli et supprimée quand l'encadrant arrête le partage. Les membres du
 * groupe la voient se mettre à jour.
 */
#[ORM\Entity(repositoryClass: TrainingGroupPositionRepository::class)]
#[ORM\Table(name: 'training_group_position')]
#[ORM\UniqueConstraint(name: 'uniq_tgp_group_user', columns: ['training_group_id', 'user_id'])]
class TrainingGroupPosition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingGroup::class)]
    #[ORM\JoinColumn(name: 'training_group_id', nullable: false, onDelete: 'CASCADE')]
    private TrainingGroup $trainingGroup;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $latitude;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    private string $longitude;

    /** Précision annoncée par le GPS, en mètres ; null = inconnue. */
    #[ORM\Column(nullable: true)]
    private ?int $accuracy = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(TrainingGroup $trainingGroup, User $user, float $latitude, float $longitude, ?int $accuracy)
    {
        $this->trainingGroup = $trainingGroup;
        $this->user = $user;
        $this->moveTo($latitude, $longitude, $accuracy);
    }

    public function moveTo(float $latitude, float $longitude, ?int $accuracy): self
    {
        $this->latitude = number_format($latitude, 6, '.', '');
        $this->longitude = number_format($longitude, 6, '.', '');
        $this->accuracy = $accuracy;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getTrainingGroup(): TrainingGroup { return $this->trainingGroup; }
    public function getUser(): User { return $this->user; }
    public function getLatitude(): float { return (float) $this->latitude; }
    public function getLongitude(): float { return (float) $this->longitude; }
    public function getAccuracy(): ?int { return $this->accuracy; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
