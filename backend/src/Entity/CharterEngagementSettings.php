<?php

namespace App\Entity;

use App\Repository\CharterEngagementSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Engagements du formulaire d'acceptation — singleton (une seule ligne
 * en base). Partagés entre tous les kinds de ClubCharter (Nouveaux /
 * Renouvellements / Tous) : le contenu textuel du formulaire varie
 * par kind, mais les cases à cocher restent identiques pour tous les
 * adhérents. La spécificité par profil (Parent/Jeune vs Sénior) est
 * portée par le champ `audience` de chaque engagement, indépendante
 * du kind.
 */
#[ORM\Entity(repositoryClass: CharterEngagementSettingsRepository::class)]
#[ORM\Table(name: 'charter_engagement_settings')]
#[ORM\HasLifecycleCallbacks]
class CharterEngagementSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Schéma du formulaire. Mêmes règles que ClubCharter avant refacto :
     *   { id, label, type: 'checkbox', required: true, description?,
     *     audience?: 'all'|'parent_jeune'|'senior' }
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fields = null;

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

    /** @return list<array<string, mixed>>|null */
    public function getFields(): ?array { return $this->fields; }

    /** @param list<array<string, mixed>>|null $fields */
    public function setFields(?array $fields): self
    {
        $this->fields = ($fields === null || $fields === []) ? null : $fields;
        return $this;
    }

    public function hasEngagements(): bool
    {
        return $this->fields !== null && count($this->fields) > 0;
    }

    /**
     * Représentation texte (JSON pretty-printed) pour édition textarea.
     */
    public function getFieldsJson(): string
    {
        if ($this->fields === null || $this->fields === []) {
            return '';
        }
        return json_encode(
            $this->fields,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ) ?: '';
    }

    public function setFieldsJson(?string $json): self
    {
        $json = trim((string) $json);
        if ($json === '') {
            $this->fields = null;
            return $this;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Le schéma doit être un tableau JSON valide.');
        }
        $this->fields = $decoded;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return 'Engagements'; }
}
