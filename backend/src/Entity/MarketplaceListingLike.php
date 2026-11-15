<?php

namespace App\Entity;

use App\Repository\MarketplaceListingLikeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * « J'aime » d'un adhérent sur une annonce de la bourse aux équipements : l'annonce reste dans sa liste (bloc « Annonces
 * que j'aime ») tant que son auteur ne l'a pas supprimée, même quand elle a expiré (plus de LIFETIME_DAYS jours) ou
 * qu'elle est en pause — alors qu'elle a disparu de la liste du club. Un seul « J'aime » par adhérent et par annonce.
 *
 * Supprimer l'annonce (ou le compte) supprime les « J'aime » (ON DELETE CASCADE).
 */
#[ORM\Entity(repositoryClass: MarketplaceListingLikeRepository::class)]
#[ORM\Table(name: 'marketplace_listing_like')]
#[ORM\UniqueConstraint(name: 'uniq_marketplace_listing_like_user', columns: ['listing_id', 'user_id'])]
class MarketplaceListingLike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MarketplaceListing $listing;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(MarketplaceListing $listing, User $user)
    {
        $this->listing = $listing;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getListing(): MarketplaceListing { return $this->listing; }
    public function getUser(): User { return $this->user; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
