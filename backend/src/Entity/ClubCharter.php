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

    /**
     * @return Collection<int, CharterAcceptance>
     */
    public function getAcceptances(): Collection { return $this->acceptances; }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->title ?? 'Charte', $this->version ?? '?');
    }
}
