<?php

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Article $article;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 2000)]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Article $article, User $user, string $content)
    {
        $this->article = $article;
        $this->user = $user;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getArticle(): Article { return $this->article; }
    public function getUser(): User { return $this->user; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $content): self { $this->content = $content; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
