<?php

namespace App\Entity;

use App\Repository\MarketplaceConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Discussion entre UN acheteur potentiel et le vendeur (auteur de
 * l'annonce) à propos d'UNE annonce de la bourse aux équipements.
 * Une seule conversation par couple (annonce, acheteur) : un acheteur
 * qui réécrit reprend la même discussion. Le vendeur, lui, en a une par
 * personne intéressée.
 *
 * Supprimer l'annonce supprime ses conversations (FK en cascade).
 *
 * Le sujet est SOIT une annonce (`listing`) SOIT une offre de la bourse
 * aux dossards (`bibOffer`) — exactement l'un des deux est renseigné.
 */
#[ORM\Entity(repositoryClass: MarketplaceConversationRepository::class)]
#[ORM\Table(name: 'marketplace_conversation')]
#[ORM\UniqueConstraint(name: 'uniq_mp_conversation_listing_buyer', columns: ['listing_id', 'buyer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_mp_conversation_bib_buyer', columns: ['bib_offer_id', 'buyer_id'])]
#[ORM\Index(name: 'idx_mp_conversation_buyer', columns: ['buyer_id'])]
class MarketplaceConversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class)]
    #[ORM\JoinColumn(name: 'listing_id', nullable: true, onDelete: 'CASCADE')]
    private ?MarketplaceListing $listing = null;

    #[ORM\ManyToOne(targetEntity: BibOffer::class)]
    #[ORM\JoinColumn(name: 'bib_offer_id', nullable: true, onDelete: 'CASCADE')]
    private ?BibOffer $bibOffer = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'buyer_id', nullable: false, onDelete: 'CASCADE')]
    private User $buyer;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Date du dernier message — sert au tri de la liste des discussions. */
    #[ORM\Column]
    private \DateTimeImmutable $lastMessageAt;

    /**
     * @var Collection<int, MarketplaceMessage>
     */
    #[ORM\OneToMany(targetEntity: MarketplaceMessage::class, mappedBy: 'conversation', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $messages;

    public function __construct(MarketplaceListing|BibOffer $subject, User $buyer)
    {
        if ($subject instanceof MarketplaceListing) {
            $this->listing = $subject;
        } else {
            $this->bibOffer = $subject;
        }
        $this->buyer = $buyer;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastMessageAt = $this->createdAt;
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getListing(): ?MarketplaceListing { return $this->listing; }
    public function getBibOffer(): ?BibOffer { return $this->bibOffer; }
    public function getBuyer(): User { return $this->buyer; }

    public function getSeller(): User
    {
        return $this->listing !== null ? $this->listing->getAuthor() : $this->bibOffer->getAuthor();
    }

    /** 'listing' (bourse aux équipements) ou 'bib' (bourse aux dossards). */
    public function getSubjectKind(): string
    {
        return $this->listing !== null ? 'listing' : 'bib';
    }

    /** Titre affiché du sujet : titre de l'annonce ou « Dossard <course> ». */
    public function getSubjectTitle(): string
    {
        return $this->listing !== null ? $this->listing->getTitle() : (string) $this->bibOffer;
    }

    /** Sujet masqué (mis en pause par son auteur). */
    public function isSubjectPaused(): bool
    {
        return $this->listing !== null ? $this->listing->isPaused() : $this->bibOffer->isPaused();
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastMessageAt(): \DateTimeImmutable { return $this->lastMessageAt; }
    public function touchLastMessageAt(): self { $this->lastMessageAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, MarketplaceMessage> */
    public function getMessages(): Collection { return $this->messages; }

    public function isParticipant(User $user): bool
    {
        return $user->getId() === $this->buyer->getId() || $user->getId() === $this->getSeller()->getId();
    }

    /** L'interlocuteur de $user dans cette discussion. */
    public function getOtherParticipant(User $user): User
    {
        return $user->getId() === $this->buyer->getId() ? $this->getSeller() : $this->buyer;
    }
}
