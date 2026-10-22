<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Entity\Trait\ContentAudienceAwareTrait;
use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Table(name: 'article')]
#[ORM\HasLifecycleCallbacks]
class Article implements OwnedContentInterface
{
    use AudienceAwareTrait;
    use ContentAudienceAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $title;

    /**
     * Emoji court affiché devant le titre de l'article (résumé mobile).
     * Nullable → pas d'emoji ajouté par défaut. 32 chars pour absorber
     * les séquences composées (ZWJ, drapeaux, modificateurs de teint).
     */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $icon = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $content = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private bool $notifyOnPublish = false;

    /**
     * Horodate l'envoi des emails de notification. NULL = pas encore
     * envoyés. Assure l'idempotence en cas de retry de la queue
     * Messenger ou de re-publication.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailsSentAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, ArticlePhoto>
     */
    #[ORM\OneToMany(targetEntity: ArticlePhoto::class, mappedBy: 'article', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

    /**
     * @var Collection<int, Comment>
     */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'article', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    /**
     * @var Collection<int, Reaction>
     */
    #[ORM\OneToMany(targetEntity: Reaction::class, mappedBy: 'article', cascade: ['remove'], orphanRemoval: true)]
    private Collection $reactions;

    /**
     * Pièces jointes (PDF, docs, GPX, images additionnelles…).
     * Différent de `photos` qui est spécifique aux images de la galerie.
     *
     * @var Collection<int, ArticleAttachment>
     */
    #[ORM\OneToMany(targetEntity: ArticleAttachment::class, mappedBy: 'article', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['uploadedAt' => 'ASC'])]
    private Collection $attachments;

    /**
     * Événements du calendrier intégrés à l'article (rendus comme dans
     * « Prochainement » côté mobile). Sélection parmi les événements
     * existants — la suppression d'un événement retire juste le lien.
     *
     * @var Collection<int, Event>
     */
    #[ORM\ManyToMany(targetEntity: Event::class)]
    #[ORM\JoinTable(name: 'article_event')]
    #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'event_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['startsAt' => 'ASC'])]
    private Collection $events;

    /**
     * Créneaux d'entraînement OCCASIONNELS intégrés à l'article (hors semaine type), un ou plusieurs : l'appli les
     * affiche en fin d'article, chacun cliquable vers son détail (et son inscription éventuelle). La suppression d'un
     * créneau retire juste le lien.
     *
     * @var Collection<int, TrainingSlot>
     */
    #[ORM\ManyToMany(targetEntity: TrainingSlot::class)]
    #[ORM\JoinTable(name: 'article_training_slot')]
    #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'training_slot_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $trainingSlots;

    /**
     * Déplacement auquel l'article est dédié. Un article dédié est réservé aux participants de ce déplacement : il
     * n'apparaît pas dans le fil d'actualités général et n'est lisible que par eux. RESTRICT : un déplacement qui
     * a des articles dédiés ne peut pas être supprimé (ils deviendraient publics ou orphelins).
     */
    #[ORM\ManyToOne(targetEntity: Trip::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(name: 'trip_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Trip $trip = null;

    /**
     * Stage auquel l'article est dédié : même règle que pour un déplacement (réservé aux participants, absent du
     * fil général). Un article est dédié à un déplacement OU à un stage, pas aux deux.
     */
    #[ORM\ManyToOne(targetEntity: Stage::class, inversedBy: 'articles')]
    #[ORM\JoinColumn(name: 'stage_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Stage $stage = null;

    /**
     * Uploads transitoires depuis le formulaire admin. Non persisté.
     * @var array<int, \Symfony\Component\HttpFoundation\File\UploadedFile>|null
     */
    private ?array $newAttachments = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->photos = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->reactions = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->trainingSlots = new ArrayCollection();
    }

    public function getTrip(): ?Trip { return $this->trip; }
    public function setTrip(?Trip $trip): self { $this->trip = $trip; return $this; }

    public function getStage(): ?Stage { return $this->stage; }
    public function setStage(?Stage $stage): self { $this->stage = $stage; return $this; }

    #[Assert\Callback]
    public function validateDedicatedTarget(ExecutionContextInterface $context): void
    {
        if ($this->trip !== null && $this->stage !== null) {
            $context->buildViolation('Un article est dédié à un déplacement OU à un stage, pas aux deux.')
                ->atPath('stage')->addViolation();
        }
    }

    /** @return Collection<int, ArticleAttachment> */
    public function getAttachments(): Collection { return $this->attachments; }

    /** @return Collection<int, Event> */
    public function getEvents(): Collection { return $this->events; }

    public function addEvent(Event $event): self
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
        }
        return $this;
    }

    public function removeEvent(Event $event): self
    {
        $this->events->removeElement($event);
        return $this;
    }

    /** @return Collection<int, TrainingSlot> */
    public function getTrainingSlots(): Collection { return $this->trainingSlots; }

    public function addTrainingSlot(TrainingSlot $slot): self
    {
        if (!$this->trainingSlots->contains($slot)) {
            $this->trainingSlots->add($slot);
        }
        return $this;
    }

    public function removeTrainingSlot(TrainingSlot $slot): self
    {
        $this->trainingSlots->removeElement($slot);
        return $this;
    }

    /** Seuls les créneaux occasionnels s'intègrent à un article. */
    #[Assert\Callback]
    public function validateTrainingSlots(ExecutionContextInterface $context): void
    {
        foreach ($this->trainingSlots as $slot) {
            if ($slot->getTemplate() !== null) {
                $context->buildViolation('Seuls les créneaux occasionnels peuvent être intégrés à un article.')
                    ->atPath('trainingSlots')->addViolation();

                return;
            }
        }
    }

    /** @return array<int, \Symfony\Component\HttpFoundation\File\UploadedFile>|null */
    public function getNewAttachments(): ?array { return $this->newAttachments; }
    /** @param array<int, \Symfony\Component\HttpFoundation\File\UploadedFile>|null $files */
    public function setNewAttachments(?array $files): self { $this->newAttachments = $files; return $this; }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $trimmed = $icon !== null ? trim($icon) : null;
        $this->icon = ($trimmed === null || $trimmed === '') ? null : $trimmed;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    /** Propriétaire au sens de ContentDeleteVoter : l'auteur de l'article. */
    public function getOwner(): ?User
    {
        return $this->author;
    }

    public function setAuthor(User $author): self
    {
        $this->author = $author;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null && $this->publishedAt <= new \DateTimeImmutable();
    }

    public function getEmailsSentAt(): ?\DateTimeImmutable { return $this->emailsSentAt; }
    public function setEmailsSentAt(?\DateTimeImmutable $at): self { $this->emailsSentAt = $at; return $this; }

    public function isNotifyOnPublish(): bool
    {
        return $this->notifyOnPublish;
    }

    public function setNotifyOnPublish(bool $notifyOnPublish): self
    {
        $this->notifyOnPublish = $notifyOnPublish;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, ArticlePhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(ArticlePhoto $photo): self
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setArticle($this);
        }
        return $this;
    }

    public function removePhoto(ArticlePhoto $photo): self
    {
        $this->photos->removeElement($photo);
        return $this;
    }

    /**
     * @return Collection<int, Comment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    /**
     * @return Collection<int, Reaction>
     */
    public function getReactions(): Collection
    {
        return $this->reactions;
    }

    /**
     * @return array<string, int>  emoji => count
     */
    public function getReactionCounts(): array
    {
        $counts = [];
        foreach ($this->reactions as $r) {
            $counts[$r->getEmoji()] = ($counts[$r->getEmoji()] ?? 0) + 1;
        }
        return $counts;
    }
}
