<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Repository\AdminNoticeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message ponctuel important poussé aux adhérents en cours de saison —
 * indépendant du tunnel de charte (qui est un formulaire complexe posé
 * au début de saison). L'user doit ACQUITTER (« J'ai compris ») ; tant
 * qu'il ne l'a pas fait, le message s'affiche au démarrage et à chaque
 * retour de background après un temps d'inactivité (>10 min côté mobile).
 *
 * Statut de publication :
 *  - publishedAt null              → brouillon (invisible)
 *  - publishedAt <= now            → actif (visible aux non-acquitteurs)
 *  - expiresAt < now (si défini)  → expiré (masqué, mais historique conservé)
 */
#[ORM\Entity(repositoryClass: AdminNoticeRepository::class)]
#[ORM\Table(name: 'admin_notice')]
#[ORM\Index(name: 'idx_admin_notice_published', columns: ['published_at'])]
class AdminNotice
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

    /** Contenu HTML riche (édité via TextEditorField dans EasyAdmin). */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le contenu est obligatoire.')]
    private string $content = '';

    /**
     * Libellé du bouton d'acquittement. Personnalisable pour adapter
     * le ton (« J'ai compris », « Ok, merci », « J'accepte », etc.).
     */
    #[ORM\Column(length: 60, options: ['default' => 'J\'ai compris'])]
    private string $acknowledgeLabel = 'J\'ai compris';

    /** Publiée à partir de cette date. Null = brouillon. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** Après cette date, plus affichée. Null = pas d'expiration. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

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

    public function getContent(): string { return $this->content; }
    public function setContent(string $c): self { $this->content = $c; return $this; }

    public function getAcknowledgeLabel(): string { return $this->acknowledgeLabel; }
    public function setAcknowledgeLabel(string $l): self
    {
        $clean = trim($l);
        $this->acknowledgeLabel = $clean !== '' ? $clean : 'J\'ai compris';
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $d): self { $this->publishedAt = $d; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $d): self { $this->expiresAt = $d; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isPublished(?\DateTimeImmutable $now = null): bool
    {
        if ($this->publishedAt === null) return false;
        $now ??= new \DateTimeImmutable();
        return $this->publishedAt <= $now;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) return false;
        $now ??= new \DateTimeImmutable();
        return $this->expiresAt < $now;
    }

    /** True si la notice est actuellement visible pour les non-acquitteurs. */
    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return $this->isPublished($now) && !$this->isExpired($now);
    }

    public function __toString(): string
    {
        return $this->title !== '' ? $this->title : '#'.($this->id ?? '?');
    }
}
