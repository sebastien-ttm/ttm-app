<?php

namespace App\Entity;

use App\Enum\BoardItemType;
use App\Enum\BoardPole;
use App\Repository\BoardAgendaItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Point à l'ordre du jour d'une réunion du CoDir, sur le modèle du compte rendu :
 * Pôle · Qui s'exprime ? · Information/Sujet/Question · Qui répond ? · Réponse/Décision · Porteur action.
 *
 * `author` = le compte qui a ajouté le point (dans l'appli, ou le secrétaire en séance) ; `speaker` = « Qui
 * s'exprime ? », en nom complet : par défaut l'auteur, s'il est membre du CoDir ou entraîneur invité (seuls ceux-là
 * peuvent s'exprimer ; le back-office choisit l'intervenant dans cette liste). Les trois derniers champs sont saisis en séance.
 *
 * Les membres du CoDir peuvent commenter un point (réponse s'il s'agit d'une Question), sans limite de nombre, et
 * répondre aux commentaires des autres tant que la réunion est ouverte : `comments` (fil via getThreadedComments()). En séance, des actions (`actions`) sont confiées à un ou plusieurs porteurs ;
 * `actionOwner` (texte libre) ne sert plus que pour les points saisis avant l'introduction des actions.
 */
#[ORM\Entity(repositoryClass: BoardAgendaItemRepository::class)]
#[ORM\Table(name: 'board_agenda_item')]
class BoardAgendaItem
{
    public const TEXT_MAX = 2000;
    /** Profondeur maximale d'indentation d'un fil de commentaires (au-delà, les réponses restent au même niveau). */
    public const COMMENT_MAX_DEPTH = 2;

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

    /** @var Collection<int, BoardItemComment> */
    #[ORM\OneToMany(targetEntity: BoardItemComment::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $comments;

    /** @var Collection<int, BoardAction> */
    #[ORM\OneToMany(targetEntity: BoardAction::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $actions;

    public function __construct(BoardMeeting $meeting, BoardPole $pole, BoardItemType $type, string $text, ?User $author)
    {
        $this->meeting = $meeting;
        $this->pole = $pole;
        $this->type = $type;
        $this->setText($text);
        $this->author = $author;
        // « Qui s'exprime ? » : nom complet de l'auteur — seulement s'il est membre du CoDir ou entraîneur invité.
        $this->speaker = $author !== null && $author->canSpeakAtBoard() ? mb_substr($author->getFullName(), 0, 120) : null;
        $this->createdAt = new \DateTimeImmutable();
        $this->comments = new ArrayCollection();
        $this->actions = new ArrayCollection();
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

    /**
     * Saisie en séance des trois colonnes de réponse. Ne s'appelle surtout pas « answer » : Twig résoudrait
     * `item.answer` vers cette méthode (qui exige des arguments) au lieu de getAnswer().
     */
    public function recordAnswer(?string $respondent, ?string $answer, ?string $actionOwner): self
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

    /** @return Collection<int, BoardItemComment> */
    public function getComments(): Collection { return $this->comments; }

    public function addComment(BoardItemComment $comment): self
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
        }
        return $this;
    }

    public function removeComment(BoardItemComment $comment): self
    {
        $this->comments->removeElement($comment);
        return $this;
    }

    /**
     * Commentaires en fil : chaque commentaire de premier niveau suivi de ses réponses (récursivement, dans l'ordre
     * chronologique). `depth` (0 = premier niveau) est plafonné à COMMENT_MAX_DEPTH pour l'indentation ; `replyTo` est le
     * nom de l'auteur du commentaire auquel on répond (null au premier niveau).
     *
     * @return list<array{comment: BoardItemComment, depth: int, replyTo: ?string}>
     */
    public function getThreadedComments(): array
    {
        $present = [];
        foreach ($this->comments as $c) {
            $present[$c->getId()] = true;
        }
        $children = [];
        $roots = [];
        foreach ($this->comments as $c) {
            $parentId = $c->getParent()?->getId();
            if ($parentId !== null && isset($present[$parentId])) {
                $children[$parentId][] = $c;
            } else {
                $roots[] = $c;
            }
        }
        $out = [];
        $walk = function (BoardItemComment $c, int $depth) use (&$walk, &$out, $children): void {
            $out[] = [
                'comment' => $c,
                'depth' => min($depth, self::COMMENT_MAX_DEPTH),
                'replyTo' => $depth > 0 ? $c->getParent()?->getAuthorName() : null,
            ];
            foreach ($children[$c->getId()] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach ($roots as $root) {
            $walk($root, 0);
        }

        return $out;
    }

    /** Ce commentaire a-t-il reçu au moins une réponse ? */
    public function hasReplies(BoardItemComment $comment): bool
    {
        foreach ($this->comments as $c) {
            if ($c->getParent() !== null && $c->getParent()->getId() === $comment->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Le commentaire et toutes ses réponses (descendants), les réponses d'abord : ce qu'il faut retirer ensemble quand
     * on supprime un commentaire (la base supprime les réponses en cascade, la collection en mémoire doit suivre).
     *
     * @return list<BoardItemComment>
     */
    public function getCommentThread(BoardItemComment $root): array
    {
        $thread = [$root];
        $ids = [$root->getId() => true];
        do {
            $added = false;
            foreach ($this->comments as $c) {
                $parentId = $c->getParent()?->getId();
                if ($parentId !== null && isset($ids[$parentId]) && !isset($ids[$c->getId()])) {
                    $ids[$c->getId()] = true;
                    $thread[] = $c;
                    $added = true;
                }
            }
        } while ($added);

        return array_reverse($thread);
    }

    /** @return Collection<int, BoardAction> */
    public function getActions(): Collection { return $this->actions; }

    public function addAction(BoardAction $action): self
    {
        if (!$this->actions->contains($action)) {
            $this->actions->add($action);
        }
        return $this;
    }

    public function removeAction(BoardAction $action): self
    {
        $this->actions->removeElement($action);
        return $this;
    }

    /**
     * Tout membre (y compris l'auteur du point, pour répondre à ceux qui l'ont commenté) peut ajouter autant de
     * commentaires qu'il veut, et répondre à n'importe quel commentaire, tant que la réunion est ouverte. Le libellé
     * « commentaire » / « réponse » se déduit du type du point.
     */
    public function isCommentableBy(User $user): bool
    {
        return !$this->meeting->isClosed();
    }

    /** « Commentaires » pour une Information ou un Sujet, « Réponses » pour une Question. */
    public function getCommentsLabel(): string
    {
        return $this->type === BoardItemType::Question ? 'Réponses' : 'Commentaires';
    }

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
