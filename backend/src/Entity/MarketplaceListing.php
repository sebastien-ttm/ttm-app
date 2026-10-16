<?php

namespace App\Entity;

use App\Repository\MarketplaceListingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Annonce de la bourse aux équipements (onglet Club) : un adhérent met
 * en vente du matériel/des affaires d'occasion. Contact entre adhérents
 * géré hors-app (bouton WhatsApp côté mobile, pas de messagerie interne
 * dédiée) — l'auteur doit donc avoir un numéro de téléphone renseigné
 * pour que le bouton apparaisse côté acheteur.
 *
 * `pausedAt` : timestamp nullable plutôt qu'un enum de statut — même
 * pattern que UserMessage::senderArchivedAt. null = publiée/visible de
 * tous, non-null = mise en pause par l'auteur (reste visible pour lui
 * dans « Mes annonces », invisible pour le reste du club). La
 * suppression, elle, est définitive (pas de soft-delete).
 *
 * Expiration : une annonce se désactive d'elle-même LIFETIME_DAYS jours après `publishedAt` (mise en ligne, ou
 * dernière réactivation) — elle n'apparaît plus dans la liste du club et n'est plus joignable, mais reste dans
 * « Mes annonces » de son auteur, seul à pouvoir la réactiver (ce qui relance les 10 jours). L'état « expirée »
 * est calculé à la lecture (pas de tâche planifiée).
 */
#[ORM\Entity(repositoryClass: MarketplaceListingRepository::class)]
#[ORM\Table(name: 'marketplace_listing')]
#[ORM\Index(name: 'idx_marketplace_listing_author', columns: ['author_id'])]
class MarketplaceListing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 3000)]
    private string $description = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    /** Prix demandé en euros (facultatif) ; null = non renseigné. DECIMAL : Doctrine le manipule en chaîne. */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $price = null;

    /** Mise en ligne ou dernière réactivation par l'auteur : point de départ des LIFETIME_DAYS jours. */
    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    /**
     * @var Collection<int, MarketplaceListingPhoto>
     */
    #[ORM\OneToMany(targetEntity: MarketplaceListingPhoto::class, mappedBy: 'listing', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

    public function __construct(User $author)
    {
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
        $this->publishedAt = $this->createdAt;
        $this->photos = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAuthor(): User { return $this->author; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = trim($description); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touchUpdatedAt(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getPausedAt(): ?\DateTimeImmutable { return $this->pausedAt; }
    public function setPausedAt(?\DateTimeImmutable $d): self { $this->pausedAt = $d; return $this; }
    public function isPaused(): bool { return $this->pausedAt !== null; }

    /** Durée de vie d'une annonce, en jours, à partir de sa mise en ligne ou de sa dernière réactivation. */
    public const LIFETIME_DAYS = 10;

    /** Prix en euros, ou null s'il n'est pas renseigné. */
    public function getPrice(): ?float { return $this->price !== null ? (float) $this->price : null; }

    /** Fixe le prix (euros, ≥ 0, 2 décimales) ; null = pas de prix. */
    public function setPrice(?float $price): self
    {
        $this->price = $price !== null ? number_format(max(0.0, $price), 2, '.', '') : null;
        return $this;
    }

    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }

    /** Réactivation par l'auteur : sort de la pause et relance les LIFETIME_DAYS jours. */
    public function republish(?\DateTimeImmutable $now = null): self
    {
        $this->pausedAt = null;
        $this->publishedAt = $now ?? new \DateTimeImmutable();
        return $this;
    }

    /** Dernier instant où l'annonce est encore visible. */
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->publishedAt->modify('+'.self::LIFETIME_DAYS.' days');
    }

    /** Désactivée d'office : plus de LIFETIME_DAYS jours depuis sa mise en ligne / réactivation. */
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->getExpiresAt() <= ($now ?? new \DateTimeImmutable());
    }

    /** Visible de tout le club : ni en pause, ni expirée. */
    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return !$this->isPaused() && !$this->isExpired($now);
    }

    /** @return Collection<int, MarketplaceListingPhoto> */
    public function getPhotos(): Collection { return $this->photos; }

    /** Nombre de photos — helper d'affichage EA. */
    public function getPhotoCount(): int { return $this->photos->count(); }

    public function __toString(): string
    {
        return $this->title !== '' ? $this->title : '#'.$this->id;
    }
}
