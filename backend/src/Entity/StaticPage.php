<?php

namespace App\Entity;

use App\Repository\StaticPageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StaticPageRepository::class)]
#[ORM\Table(name: 'static_page')]
#[ORM\UniqueConstraint(name: 'uniq_static_page_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class StaticPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?$/', message: 'Le slug doit être en minuscules, chiffres et tirets uniquement.')]
    private string $slug;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $content = '';

    #[ORM\Column]
    private bool $isPublished = true;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $content): self { $this->content = $content; return $this; }
    public function isPublished(): bool { return $this->isPublished; }
    public function setIsPublished(bool $b): self { $this->isPublished = $b; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return $this->title ?? '#'.$this->id; }
}
