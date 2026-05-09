<?php

namespace App\Entity;

use App\Repository\ReactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReactionRepository::class)]
#[ORM\Table(name: 'reaction')]
#[ORM\UniqueConstraint(name: 'uniq_reaction_article_user_emoji', columns: ['article_id', 'user_id', 'emoji'])]
#[ORM\Index(name: 'idx_reaction_article', columns: ['article_id'])]
class Reaction
{
    public const ALLOWED_EMOJIS = ['👍', '❤️', '🔥', '😂', '😮', '👏'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'reactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Article $article;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $emoji;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Article $article, User $user, string $emoji)
    {
        $this->article = $article;
        $this->user = $user;
        $this->emoji = $emoji;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getArticle(): Article { return $this->article; }
    public function getUser(): User { return $this->user; }
    public function getEmoji(): string { return $this->emoji; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
