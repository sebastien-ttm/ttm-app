<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Repository\SurveyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sondage : plusieurs rubriques (questions) indépendantes, chacune
 * d'un type parmi : texte court, texte long, choix unique, choix
 * multiple. Les rubriques n'ont PAS de dépendance entre elles pour
 * l'instant (pas de logique conditionnelle).
 *
 * Schéma JSON des questions (`sections`) :
 *   [{ id, label, type: 'short_text'|'long_text'|'single_choice'|'multi_choice',
 *      required?: bool, help?: string, options?: string[] }]
 *
 * Statut :
 *  - publishedAt null              → brouillon (invisible)
 *  - publishedAt <= now            → actif (visible aux users ciblés)
 *  - closesAt < now (si défini)    → fermé (lecture seule, historique conservé)
 */
#[ORM\Entity(repositoryClass: SurveyRepository::class)]
#[ORM\Table(name: 'survey')]
#[ORM\Index(name: 'idx_survey_published', columns: ['published_at'])]
class Survey
{
    use AudienceAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(max: 200)]
    private string $title = '';

    /** Introduction affichée en tête du formulaire (HTML riche). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Schéma des questions. Voir docblock de classe pour le format.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sections = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closesAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): self { $this->title = trim($t); return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self
    {
        $t = $d !== null ? trim($d) : null;
        $this->description = ($t === '' ? null : $t);
        return $this;
    }

    /** @return list<array<string, mixed>>|null */
    public function getSections(): ?array { return $this->sections; }

    /** @param list<array<string, mixed>>|null $s */
    public function setSections(?array $s): self
    {
        $this->sections = ($s === null || $s === []) ? null : $s;
        return $this;
    }

    /** Sérialisation pretty JSON pour édition textarea côté admin. */
    public function getSectionsJson(): string
    {
        if ($this->sections === null || $this->sections === []) {
            return '';
        }
        return json_encode(
            $this->sections,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ) ?: '';
    }

    public function setSectionsJson(?string $json): self
    {
        $json = trim((string) $json);
        if ($json === '') { $this->sections = null; return $this; }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Le schéma des sections doit être un tableau JSON valide.');
        }
        $this->sections = $decoded;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $d): self { $this->publishedAt = $d; return $this; }

    public function getClosesAt(): ?\DateTimeImmutable { return $this->closesAt; }
    public function setClosesAt(?\DateTimeImmutable $d): self { $this->closesAt = $d; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isPublished(?\DateTimeImmutable $now = null): bool
    {
        if ($this->publishedAt === null) return false;
        return $this->publishedAt <= ($now ?? new \DateTimeImmutable());
    }

    public function isClosed(?\DateTimeImmutable $now = null): bool
    {
        if ($this->closesAt === null) return false;
        return $this->closesAt < ($now ?? new \DateTimeImmutable());
    }

    public function isOpen(?\DateTimeImmutable $now = null): bool
    {
        return $this->isPublished($now) && !$this->isClosed($now);
    }

    public function __toString(): string
    {
        return $this->title !== '' ? $this->title : '#'.($this->id ?? '?');
    }
}
