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

    /** @return Collection<int, MarketplaceListingPhoto> */
    public function getPhotos(): Collection { return $this->photos; }

    /** Nombre de photos — helper d'affichage EA. */
    public function getPhotoCount(): int { return $this->photos->count(); }

    public function __toString(): string
    {
        return $this->title !== '' ? $this->title : '#'.$this->id;
    }
}
