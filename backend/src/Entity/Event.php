<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Entity\Trait\ContentAudienceAwareTrait;
use App\Enum\EventType;
use App\Repository\EventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'event')]
#[ORM\Index(name: 'idx_event_starts_at', columns: ['starts_at'])]
class Event
{
    use AudienceAwareTrait;
    use ContentAudienceAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column]
    #[Assert\NotNull]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 16, enumType: EventType::class)]
    private EventType $type = EventType::Entrainement;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^#[0-9A-Fa-f]{6}$/', message: 'Format hex attendu : #RRGGBB')]
    private ?string $color = null;

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $d): self { $this->startsAt = $d; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): self { $this->endsAt = $d; return $this; }
    public function getType(): EventType { return $this->type; }
    public function setType(EventType $type): self { $this->type = $type; return $this; }

    public function getColor(): string
    {
        return $this->color ?? $this->type->defaultColor();
    }

    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function __toString(): string { return $this->title ?? '#'.$this->id; }
}
