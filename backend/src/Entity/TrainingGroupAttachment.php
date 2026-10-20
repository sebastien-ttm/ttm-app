<?php

namespace App\Entity;

use App\Repository\TrainingGroupAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace GPX (ou autre fichier) ajoutée par un encadrant à un groupe d'entraînement, téléchargeable par ses membres.
 * Fichier stocké dans var/uploads/training-groups/{groupId}/ (voir TrainingGroupFileService).
 */
#[ORM\Entity(repositoryClass: TrainingGroupAttachmentRepository::class)]
#[ORM\Table(name: 'training_group_attachment')]
#[ORM\Index(name: 'idx_tga_group', columns: ['training_group_id'])]
class TrainingGroupAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingGroup::class)]
    #[ORM\JoinColumn(name: 'training_group_id', nullable: false, onDelete: 'CASCADE')]
    private TrainingGroup $trainingGroup;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: false, onDelete: 'CASCADE')]
    private User $uploadedBy;

    #[ORM\Column(length: 64)]
    private string $storedName;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(TrainingGroup $trainingGroup, User $uploadedBy, string $storedName, string $originalName, string $mimeType, int $size)
    {
        $this->trainingGroup = $trainingGroup;
        $this->uploadedBy = $uploadedBy;
        $this->storedName = $storedName;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->mimeType = mb_substr($mimeType, 0, 100);
        $this->size = $size;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTrainingGroup(): TrainingGroup { return $this->trainingGroup; }
    public function getUploadedBy(): User { return $this->uploadedBy; }
    public function getStoredName(): string { return $this->storedName; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }

    public function getHumanSize(): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $i = 0;
        $s = (float) $this->size;
        while ($s >= 1024 && $i < count($units) - 1) {
            $s /= 1024;
            $i++;
        }
        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $s, $units[$i]);
    }
}
