<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Repository\StaticPageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StaticPageRepository::class)]
#[ORM\Table(name: 'static_page')]
#[ORM\UniqueConstraint(name: 'uniq_static_page_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class StaticPage
{
    use AudienceAwareTrait;

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
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StaticPage $parent = null;

    /**
     * @var Collection<int, StaticPage>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'title' => 'ASC'])]
    private Collection $children;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->children = new ArrayCollection();
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

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    public function getParent(): ?StaticPage { return $this->parent; }

    public function setParent(?StaticPage $parent): self
    {
        // Defensive : prevent setting self as parent (creates infinite loop)
        if ($parent === $this) {
            throw new \InvalidArgumentException('Une page ne peut pas être son propre parent.');
        }
        // Walk up the chain to detect cycles
        $cursor = $parent;
        while ($cursor !== null) {
            if ($cursor === $this) {
                throw new \InvalidArgumentException("Référence circulaire détectée dans l'arborescence des pages.");
            }
            $cursor = $cursor->parent;
        }
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, StaticPage>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /**
     * @return list<StaticPage>  immediate published children, ordered
     */
    public function getPublishedChildren(): array
    {
        return $this->children
            ->filter(fn (StaticPage $p) => $p->isPublished())
            ->getValues();
    }

    public function getDepth(): int
    {
        $depth = 0;
        $cursor = $this->parent;
        while ($cursor !== null) {
            $depth++;
            $cursor = $cursor->getParent();
        }
        return $depth;
    }

    public function __toString(): string { return $this->title ?? '#'.$this->id; }
}
