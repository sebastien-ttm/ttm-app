<?php

namespace App\Entity;

use App\Repository\PoolBadgeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * QR code (badge piscines) que les adhérents présentent à l'accueil
 * pour accéder aux piscines partenaires.
 *
 * Singleton de facto : une seule ligne en BDD à la fois, gérée via
 * le repo (findCurrent / findOrCreate).
 */
#[ORM\Entity(repositoryClass: PoolBadgeRepository::class)]
#[ORM\Table(name: 'pool_badge')]
#[Vich\Uploadable]
class PoolBadge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[Vich\UploadableField(mapping: 'pool_badges', fileNameProperty: 'imagePath')]
    #[Assert\Image(maxSize: '5M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])]
    private ?File $file = null;

    /** Libellé visible côté mobile (ex. « Saison 2025-2026 »). */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $title = null;

    /** Texte d'aide (ex. « À présenter à l'accueil de la piscine ») — optionnel. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getImagePath(): ?string { return $this->imagePath; }
    public function setImagePath(?string $imagePath): self { $this->imagePath = $imagePath; return $this; }

    public function getFile(): ?File { return $this->file; }
    public function setFile(?File $file): self
    {
        $this->file = $file;
        if ($file !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function hasImage(): bool { return $this->imagePath !== null; }

    public function __toString(): string
    {
        return $this->title ?? 'Badge piscines #'.$this->id;
    }
}
