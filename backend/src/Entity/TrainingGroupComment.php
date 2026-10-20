<?php

namespace App\Entity;

use App\Repository\TrainingGroupCommentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Commentaire d'un encadrant dans un groupe d'entraînement : un texte et/ou un point GPS (rendez-vous, point de
 * ravitaillement, changement de parcours…).
 */
#[ORM\Entity(repositoryClass: TrainingGroupCommentRepository::class)]
#[ORM\Table(name: 'training_group_comment')]
#[ORM\Index(name: 'idx_tgc_group', columns: ['training_group_id'])]
class TrainingGroupComment
{
    public const CONTENT_MAX = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Propriété nommée « trainingGroup » (et non « group ») : GROUP est un mot réservé de SQL et de DQL.
    #[ORM\ManyToOne(targetEntity: TrainingGroup::class)]
    #[ORM\JoinColumn(name: 'training_group_id', nullable: false, onDelete: 'CASCADE')]
    private TrainingGroup $trainingGroup;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $longitude = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(TrainingGroup $trainingGroup, User $author, ?string $content, ?float $latitude, ?float $longitude)
    {
        $this->trainingGroup = $trainingGroup;
        $this->author = $author;
        $content = $content !== null ? trim($content) : '';
        $this->content = $content === '' ? null : mb_substr($content, 0, self::CONTENT_MAX);
        if ($latitude !== null && $longitude !== null) {
            $this->latitude = number_format($latitude, 6, '.', '');
            $this->longitude = number_format($longitude, 6, '.', '');
        }
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTrainingGroup(): TrainingGroup { return $this->trainingGroup; }
    public function getAuthor(): User { return $this->author; }
    public function getContent(): ?string { return $this->content; }
    public function getLatitude(): ?float { return $this->latitude !== null ? (float) $this->latitude : null; }
    public function getLongitude(): ?float { return $this->longitude !== null ? (float) $this->longitude : null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
