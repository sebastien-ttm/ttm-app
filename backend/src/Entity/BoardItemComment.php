<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Commentaire d'un membre du CoDir sur un point proposé par un autre membre : un commentaire pour une
 * Information ou un Sujet, une réponse pour une Question (le libellé se déduit du type du point).
 *
 * `authorName` est une copie du nom au moment de la publication : le compte rendu reste lisible même si le compte
 * de l'auteur est supprimé plus tard.
 */
#[ORM\Entity]
#[ORM\Table(name: 'board_item_comment')]
class BoardItemComment
{
    public const TEXT_MAX = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BoardAgendaItem::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BoardAgendaItem $item;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(length: 120)]
    private string $authorName;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::TEXT_MAX)]
    private string $text = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(BoardAgendaItem $item, User $author, string $text)
    {
        $this->item = $item;
        $this->author = $author;
        $this->authorName = mb_substr($author->getFullName(), 0, 120);
        $this->text = mb_substr(trim($text), 0, self::TEXT_MAX);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getItem(): BoardAgendaItem { return $this->item; }
    public function getAuthor(): ?User { return $this->author; }
    public function getAuthorName(): string { return $this->authorName; }
    public function getText(): string { return $this->text; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isAuthoredBy(User $user): bool
    {
        return $this->author !== null && $this->author->getId() === $user->getId();
    }
}
