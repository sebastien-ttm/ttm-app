<?php

namespace App\Entity;

use App\Repository\MarketplaceListingPhotoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une photo d'une annonce de la bourse aux équipements. Jusqu'à 5 par
 * annonce (limite appliquée côté MarketplaceController, pas en base).
 */
#[ORM\Entity(repositoryClass: MarketplaceListingPhotoRepository::class)]
#[ORM\Table(name: 'marketplace_listing_photo')]
#[ORM\Index(name: 'idx_marketplace_photo_listing', columns: ['listing_id'])]
class MarketplaceListingPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'listing_id', nullable: false, onDelete: 'CASCADE')]
    private MarketplaceListing $listing;

    /** Nom de fichier stocké sur disque (avec hash pour unicité). */
    #[ORM\Column(length: 255)]
    private string $storedName;

    /** Ordre d'affichage (0 = 1re photo affichée dans la liste). */
    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(MarketplaceListing $listing, string $storedName, int $position)
    {
        $this->listing = $listing;
        $this->storedName = $storedName;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getListing(): MarketplaceListing { return $this->listing; }
    public function getStoredName(): string { return $this->storedName; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
