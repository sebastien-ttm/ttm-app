<?php

namespace App\Entity;

use App\Enum\BoardItemType;
use App\Enum\BoardPole;
use App\Repository\BoardAgendaItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Point à l'ordre du jour d'une réunion du CoDir, sur le modèle du compte rendu :
 * Pôle · Qui s'exprime ? · Information/Sujet/Question · Qui répond ? · Réponse/Décision · Porteur action.
 *
 * `author` = le compte qui a ajouté le point (dans l'appli, ou le secrétaire en séance) ; `speaker` = « Qui
 * s'exprime ? », par défaut le prénom de l'auteur. Les trois derniers champs sont saisis en séance.
 */
#[ORM\Entity(repositoryClass: BoardAgendaItemRepository::class)]
#[ORM\Table(name: 'board_agenda_item')]
class BoardAgendaItem
{
    public const TEXT_MAX = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BoardMeeting::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BoardMeeting $meeting;

    #[ORM\Column(length: 32, enumType: BoardPole::class)]
    private BoardPole $pole;

    #[ORM\Column(length: 16, enumType: BoardItemType::class)]
    private BoardItemType $type;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::TEXT_MAX)]
    private string $text = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** « Qui s'exprime ? » */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $speaker = null;

    /** « Qui répond ? » (saisi en séance) */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $respondent = null;

    /** « Réponse/Décision » (saisie en séance) */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: self::TEXT_MAX)]
    private ?string $answer = null;

    /** « Porteur action » (saisi en séance) */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $actionOwner = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    public function __construct(BoardMeeting $meeting, BoardPole $pole, BoardItemType $type, string $text, ?User $author)
    {
        $this->meeting = $meeting;
        $this->pole = $pole;
        $this->type = $type;
        $this->setText($text);
        $this->author = $author;
        $this->speaker = $author?->getPrenom();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMeeting(): BoardMeeting { return $this->meeting; }

    public function getPole(): BoardPole { return $this->pole; }
    public function setPole(BoardPole $pole): self { $this->pole = $pole; return $this; }

    public function getType(): BoardItemType { return $this->type; }
    public function setType(BoardItemType $type): self { $this->type = $type; return $this; }

    public function getText(): string { return $this->text; }
    public function setText(string $text): self { $this->text = mb_substr(trim($text), 0, self::TEXT_MAX); return $this; }

    public function getAuthor(): ?User { return $this->author; }

    public function getSpeaker(): ?string { return $this->speaker; }
    public function setSpeaker(?string $s): self { $this->speaker = self::clean($s, 120); return $this; }

    public function getRespondent(): ?string { return $this->respondent; }
    public function getAnswer(): ?string { return $this->answer; }
    public function getActionOwner(): ?string { return $this->actionOwner; }
    public function getAnsweredAt(): ?\DateTimeImmutable { return $this->answeredAt; }

    /** Saisie en séance des trois colonnes de réponse. */
    public function answer(?string $respondent, ?string $answer, ?string $actionOwner): self
    {
        $this->respondent = self::clean($respondent, 120);
        $this->answer = self::clean($answer, self::TEXT_MAX);
        $this->actionOwner = self::clean($actionOwner, 120);
        $this->answeredAt = $this->isAnswered() ? ($this->answeredAt ?? new \DateTimeImmutable()) : null;
        return $this;
    }

    public function isAnswered(): bool
    {
        return $this->answer !== null || $this->respondent !== null || $this->actionOwner !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** L'auteur peut modifier / supprimer son point tant que la réunion est ouverte et qu'il n'a pas été traité. */
    public function isEditableBy(User $user): bool
    {
        return !$this->meeting->isClosed()
            && !$this->isAnswered()
            && $this->author !== null
            && $this->author->getId() === $user->getId();
    }

    private static function clean(?string $s, int $max): ?string
    {
        $s = $s !== null ? trim($s) : null;
        return $s !== null && $s !== '' ? mb_substr($s, 0, $max) : null;
    }
}
