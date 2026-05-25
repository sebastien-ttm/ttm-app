<?php

namespace App\Entity;

use App\Repository\TrainingSlotAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pièce jointe attachée à un créneau d'entraînement (ex. GPX de la sortie vélo,
 * PDF de l'échauffement, etc.). Une PJ appartient à UN créneau précis ; pour
 * partager le même fichier sur plusieurs semaines, il faut le ré-uploader.
 */
#[ORM\Entity(repositoryClass: TrainingSlotAttachmentRepository::class)]
#[ORM\Table(name: 'training_slot_attachment')]
#[ORM\Index(name: 'idx_attachment_slot', columns: ['slot_id'])]
class TrainingSlotAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingSlot::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingSlot $slot;

    /** Nom de fichier stocké sur disque (avec hash pour unicité). */
    #[ORM\Column(length: 255)]
    private string $storedName;

    /** Nom d'origine affiché à l'utilisateur. */
    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        TrainingSlot $slot,
        string $storedName,
        string $originalName,
        string $mimeType,
        int $size,
    ) {
        $this->slot = $slot;
        $this->storedName = $storedName;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlot(): TrainingSlot { return $this->slot; }
    public function getStoredName(): string { return $this->storedName; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }

    /** Taille humanisée pour l'UI. */
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
