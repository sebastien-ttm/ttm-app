<?php

namespace App\Entity;

use App\Repository\ArticleReadRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Article lu par un adhérent (il a ouvert sa page dans l'appli) — 1 ligne par couple (user, article).
 * Sert à présenter autrement, dans la liste « Actualités », les articles déjà lus. Les lectures
 * répétées n'ajoutent pas de ligne (INSERT IGNORE sur l'index unique).
 */
#[ORM\Entity(repositoryClass: ArticleReadRepository::class)]
#[ORM\Table(name: 'article_read')]
#[ORM\UniqueConstraint(name: 'uniq_article_read_user_article', columns: ['user_id', 'article_id'])]
#[ORM\Index(name: 'idx_article_read_article', columns: ['article_id'])]
class ArticleRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Article::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Article $article;

    #[ORM\Column]
    private \DateTimeImmutable $readAt;

    public function __construct(User $user, Article $article, ?\DateTimeImmutable $at = null)
    {
        $this->user = $user;
        $this->article = $article;
        $this->readAt = $at ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getArticle(): Article { return $this->article; }
    public function getReadAt(): \DateTimeImmutable { return $this->readAt; }
}
