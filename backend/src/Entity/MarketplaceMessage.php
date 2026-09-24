<?php

namespace App\Entity;

use App\Repository\MarketplaceMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un message d'une discussion de la bourse aux équipements. `notifiedAt`
 * : horodatage posé AVANT l'envoi de l'e-mail au destinataire (même
 * idempotence que les autres notifications — un worker relancé ne
 * renvoie jamais deux fois le même message).
 */
#[ORM\Entity(repositoryClass: MarketplaceMessageRepository::class)]
#[ORM\Table(name: 'marketplace_message')]
#[ORM\Index(name: 'idx_mp_message_conversation', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_mp_message_author_created', columns: ['author_id', 'created_at'])]
class MarketplaceMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MarketplaceConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
    private MarketplaceConversation $conversation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    public function __construct(MarketplaceConversation $conversation, User $author, string $content)
    {
        $this->conversation = $conversation;
        $this->author = $author;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getConversation(): MarketplaceConversation { return $this->conversation; }
    public function getAuthor(): User { return $this->author; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getNotifiedAt(): ?\DateTimeImmutable { return $this->notifiedAt; }
    public function setNotifiedAt(?\DateTimeImmutable $d): self { $this->notifiedAt = $d; return $this; }
}
