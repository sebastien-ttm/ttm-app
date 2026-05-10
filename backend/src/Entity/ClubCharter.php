<?php

namespace App\Entity;

use App\Repository\ClubCharterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClubCharterRepository::class)]
#[ORM\Table(name: 'club_charter')]
#[ORM\HasLifecycleCallbacks]
class ClubCharter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $title;

    /**
     * Numéro de version / saison, ex. "2026" ou "2026-rev2".
     * Sert d'identifiant lisible et permet de tracker les changements.
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private string $version;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $content = '';

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, CharterAcceptance>
     */
    #[ORM\OneToMany(targetEntity: CharterAcceptance::class, mappedBy: 'charter', cascade: ['remove'])]
    private Collection $acceptances;

    public function __construct()
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->acceptances = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getVersion(): string { return $this->version; }
    public function setVersion(string $version): self { $this->version = $version; return $this; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $content): self { $this->content = $content; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $b): self { $this->isActive = $b; return $this; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(\DateTimeImmutable $d): self { $this->publishedAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * @return Collection<int, CharterAcceptance>
     */
    public function getAcceptances(): Collection { return $this->acceptances; }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->title ?? 'Charte', $this->version ?? '?');
    }
}
