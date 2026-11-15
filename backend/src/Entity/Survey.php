<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Repository\SurveyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
 *  - publishedAt null              → brouillon (invisible, sauf pour ses testeurs : voir $testers)
 *  - publishedAt <= now            → actif (visible aux users ciblés)
 *  - closesAt < now (si défini)    → fermé (lecture seule, historique conservé)
 */
#[ORM\Entity(repositoryClass: SurveyRepository::class)]
#[ORM\Table(name: 'survey')]
#[ORM\Index(name: 'idx_survey_published', columns: ['published_at'])]
class Survey implements OwnedContentInterface
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

    /**
     * Stage auquel le sondage est dédié : il n'apparaît alors pas dans les sondages généraux et n'est accessible
     * qu'aux participants du stage. RESTRICT : un stage qui a des éléments dédiés ne peut pas être supprimé.
     */
    #[ORM\ManyToOne(targetEntity: Stage::class, inversedBy: 'surveys')]
    #[ORM\JoinColumn(name: 'stage_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Stage $stage = null;

    public function getStage(): ?Stage { return $this->stage; }
    public function setStage(?Stage $stage): self { $this->stage = $stage; return $this; }

    /**
     * Testeurs : adhérents (l'administrateur lui-même compris) qui voient et peuvent essayer le sondage dans l'appli
     * AVANT sa publication, sans que personne d'autre le voie. Leurs réponses d'essai ne sont jamais enregistrées
     * (pas de résultat faussé, pas de rattachement à un groupe).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'survey_tester')]
    #[ORM\JoinColumn(name: 'survey_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $testers;

    /** @return Collection<int, User> */
    public function getTesters(): Collection { return $this->testers; }

    public function addTester(User $user): self
    {
        if (!$this->testers->contains($user)) {
            $this->testers->add($user);
        }
        return $this;
    }

    public function removeTester(User $user): self
    {
        $this->testers->removeElement($user);
        return $this;
    }

    public function hasTester(?User $user): bool
    {
        return $user !== null && $this->testers->contains($user);
    }

    /** Le viewer essaie ce sondage avant sa publication (brouillon ou publication programmée, et il est testeur). */
    public function isTestFor(?User $user, ?\DateTimeImmutable $now = null): bool
    {
        return !$this->isPublished($now) && $this->hasTester($user);
    }

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->testers = new ArrayCollection();
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

    public function getOwner(): ?User { return $this->createdBy; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /**
     * Getters « virtuels » — servent uniquement à ce qu'EasyAdmin trouve
     * une propriété sur l'entité avant d'appeler formatValue() dans la
     * colonne de liste. Sans eux, l'admin affiche « Inaccessible ».
     *
     * getResponseCount() reste à 0 : la vraie valeur est injectée par
     * formatValue() côté CRUD via SurveyResponseRepository::countForSurvey.
     */
    public function getSectionCount(): int
    {
        return count($this->sections ?? []);
    }
    public function getResponseCount(): int { return 0; }
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
