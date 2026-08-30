<?php

namespace App\Entity;

use App\Enum\AdherentKind;
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

    /**
     * Définition des champs du formulaire à remplir par l'adhérent.
     * Tableau JSON, chaque élément :
     *   {
     *     "id":       "size",                         // identifiant unique du champ
     *     "label":    "Taille T-shirt",               // libellé affiché
     *     "type":     "text|textarea|number|date|checkbox|select|radio",
     *     "required": true,                           // optionnel, défaut false
     *     "help":     "Texte d'aide",                 // optionnel
     *     "options":  ["S","M","L","XL"]              // requis pour select/radio
     *   }
     * Si vide ou null → comportement legacy (simple bouton "J'accepte").
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fields = null;

    /**
     * Aperçu privé : si non null, ce formulaire est visible UNIQUEMENT
     * par cet utilisateur (l'admin qui teste). Le reste des adhérents
     * voit le plus récent en date de publication.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'preview_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $previewUser = null;

    /**
     * Cible du formulaire : nouvel adhérent, renouvellement, ou les
     * deux (default `all`). Permet d'avoir un contenu / des engagements
     * spécifiques pour un premier adhésion vs un retour.
     */
    #[ORM\Column(length: 16, enumType: AdherentKind::class, options: ['default' => 'all'])]
    private AdherentKind $kind = AdherentKind::All;

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
    public function getPreviewUser(): ?User { return $this->previewUser; }
    public function setPreviewUser(?User $user): self { $this->previewUser = $user; return $this; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(\DateTimeImmutable $d): self { $this->publishedAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getKind(): AdherentKind { return $this->kind; }
    public function setKind(AdherentKind $k): self { $this->kind = $k; return $this; }

    /** @return list<array<string, mixed>>|null */
    public function getFields(): ?array { return $this->fields; }

    /** @param list<array<string, mixed>>|null $fields */
    public function setFields(?array $fields): self
    {
        $this->fields = ($fields === null || $fields === []) ? null : $fields;
        return $this;
    }

    public function hasForm(): bool
    {
        return $this->fields !== null && count($this->fields) > 0;
    }

    /**
     * Représentation texte (JSON pretty-printed) du schéma, utilisée
     * comme champ éditable côté admin (TextareaField). Ne touche pas
     * à la colonne tant que setFieldsJson() n'est pas appelé.
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

    /**
     * Désérialise et stocke. Lève une exception si le JSON est invalide.
     * (La validation métier — types, ids, options — est faite par
     * FormSchemaValidator côté contrôleur admin.)
     */
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

    /**
     * @return Collection<int, CharterAcceptance>
     */
    public function getAcceptances(): Collection { return $this->acceptances; }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->title ?? 'Charte', $this->version ?? '?');
    }
}
