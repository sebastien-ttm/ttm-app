<?php

namespace App\Entity;

use App\Repository\BannerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: BannerRepository::class)]
#[ORM\Table(name: 'banner')]
#[Vich\Uploadable]
class Banner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[Vich\UploadableField(mapping: 'banners', fileNameProperty: 'imagePath')]
    #[Assert\Image(maxSize: '5M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'])]
    private ?File $file = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $linkUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column]
    private bool $isActive = true;

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
    public function getLinkUrl(): ?string { return $this->linkUrl; }
    public function setLinkUrl(?string $linkUrl): self { $this->linkUrl = $linkUrl; return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $d): self { $this->startsAt = $d; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): self { $this->endsAt = $d; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $b): self { $this->isActive = $b; return $this; }

    public function isCurrentlyVisible(\DateTimeImmutable $now = null): bool
    {
        if (!$this->isActive) {
            return false;
        }
        $now = $now ?? new \DateTimeImmutable();
        if ($this->startsAt !== null && $this->startsAt > $now) {
            return false;
        }
        if ($this->endsAt !== null && $this->endsAt < $now) {
            return false;
        }
        return true;
    }

    public function __toString(): string { return $this->title ?? 'Bannière #'.$this->id; }
}
