<?php

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Commentaire attaché à un article OU un événement du calendrier, avec
 * support optionnel du threading via `parent`. Le constructeur ne fixe
 * ni l'un ni l'autre : c'est aux factories forArticle() / forEvent() /
 * reply() de garantir qu'exactement un des deux est renseigné — ne
 * pas instancier Comment directement en dehors de ces factories.
 *
 * Threading : `parent` référence un autre Comment sur le MÊME parent
 * (même article ou même événement). Il n'y a pas de limite de
 * profondeur — l'affichage indente au delà pour signaler la structure.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'idx_comment_event', columns: ['event_id'])]
#[ORM\Index(name: 'idx_comment_parent', columns: ['parent_id'])]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Event $event = null;

    /**
     * Commentaire parent — non-null quand ce commentaire est une réponse
     * threadée. ON DELETE CASCADE : supprimer un commentaire supprime
     * toute la sous-arborescence en dessous.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?Comment $parent = null;

    /**
     * @var Collection<int, Comment>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent', cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $replies;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 2000)]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Horodatage de la dernière modification par son auteur. NULL tant
     * que le commentaire n'a jamais été édité — sert à afficher un
     * indicateur « (modifié) » côté mobile.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    public function __construct(User $user, string $content)
    {
        $this->user = $user;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
        $this->replies = new ArrayCollection();
    }

    /**
     * Factory : commentaire top-level sur un article.
     */
    public static function forArticle(Article $article, User $user, string $content): self
    {
        $c = new self($user, $content);
        $c->article = $article;
        return $c;
    }

    /**
     * Factory : commentaire top-level sur un événement.
     */
    public static function forEvent(Event $event, User $user, string $content): self
    {
        $c = new self($user, $content);
        $c->event = $event;
        return $c;
    }

    /**
     * Factory : réponse threadée à un commentaire existant. Le nouveau
     * commentaire hérite automatiquement du contexte article/événement
     * de son parent, garantissant que toutes les branches restent
     * rattachées au même hôte.
     */
    public static function reply(Comment $parent, User $user, string $content): self
    {
        $c = new self($user, $content);
        $c->parent = $parent;
        $c->article = $parent->article;
        $c->event = $parent->event;
        return $c;
    }

    public function getId(): ?int { return $this->id; }
    public function getArticle(): ?Article { return $this->article; }
    public function getEvent(): ?Event { return $this->event; }
    public function getParent(): ?Comment { return $this->parent; }
    /** @return Collection<int, Comment> */
    public function getReplies(): Collection { return $this->replies; }
    public function getUser(): User { return $this->user; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEditedAt(): ?\DateTimeImmutable { return $this->editedAt; }

    /**
     * Édition par l'auteur : met à jour le contenu ET horodate l'édition.
     * Distinct de setContent() (réservé à la construction initiale via
     * les factories) pour que l'appelant ne puisse pas modifier le
     * texte sans que editedAt suive.
     */
    public function edit(string $content): self
    {
        $this->content = $content;
        $this->editedAt = new \DateTimeImmutable();
        return $this;
    }

    /** @internal réservé aux factories forArticle/forEvent/reply. */
    public function setContent(string $content): self { $this->content = $content; return $this; }
}
