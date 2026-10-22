<?php

namespace App\Entity;

use App\Repository\TripRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Déplacement du club (stage, compétition lointaine, week-end…), créé depuis le backend. Seuls les adhérents
 * participants le voient dans l'appli, avec une vue de contexte dédiée : informations pratiques, événements du
 * calendrier rattachés et articles spécifiques (non publiés dans le fil général).
 */
#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\Table(name: 'trip')]
#[ORM\HasLifecycleCallbacks]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $title = '';

    /** Emoji affiché devant le titre (même convention que Article::$icon). */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $icon = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $location = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'Indiquez la date de départ.')]
    private ?\DateTimeImmutable $startsOn = null;

    /** Dernier jour du déplacement ; null = un seul jour. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    /** Informations pratiques (HTML de l'éditeur riche : programme, hébergement, rendez-vous, matériel…). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Tant que le déplacement n'est pas publié, personne ne le voit dans l'appli (brouillon). */
    #[ORM\Column]
    private bool $published = false;

    /**
     * Participants déjà prévenus par notification push (id utilisateur) : un adhérent n'est notifié qu'une fois,
     * quand il est ajouté à un déplacement publié.
     *
     * @var list<int>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $notifiedUserIds = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'trip_participant')]
    #[ORM\JoinColumn(name: 'trip_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $participants;

    /**
     * Événements du calendrier rattachés : ils restent des événements normaux (visibles dans le calendrier selon leur
     * audience), le déplacement ne fait que les regrouper. La suppression d'un événement retire juste le lien.
     *
     * @var Collection<int, Event>
     */
    #[ORM\ManyToMany(targetEntity: Event::class)]
    #[ORM\JoinTable(name: 'trip_event')]
    #[ORM\JoinColumn(name: 'trip_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'event_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['startsAt' => 'ASC'])]
    private Collection $events;

    /**
     * Articles dédiés : réservés aux participants, absents du fil d'actualités général. Pas de cascade de
     * suppression (le lien en base est en RESTRICT) : supprimer le déplacement ne doit jamais rendre ces articles
     * publics, ni les détruire sans que l'éditeur l'ait décidé.
     *
     * @var Collection<int, Article>
     */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'trip')]
    #[ORM\OrderBy(['publishedAt' => 'DESC', 'createdAt' => 'DESC'])]
    private Collection $articles;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->articles = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->startsOn !== null && $this->endsOn !== null && $this->endsOn < $this->startsOn) {
            $context->buildViolation('La date de retour ne peut pas précéder la date de départ.')
                ->atPath('endsOn')->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self
    {
        $trimmed = $icon !== null ? trim($icon) : null;
        $this->icon = ($trimmed === null || $trimmed === '') ? null : $trimmed;
        return $this;
    }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self
    {
        $trimmed = $location !== null ? trim($location) : null;
        $this->location = ($trimmed === null || $trimmed === '') ? null : $trimmed;
        return $this;
    }

    public function getStartsOn(): ?\DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(?\DateTimeImmutable $startsOn): self { $this->startsOn = $startsOn; return $this; }

    public function getEndsOn(): ?\DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(?\DateTimeImmutable $endsOn): self { $this->endsOn = $endsOn; return $this; }

    /** Dernier jour effectif (le jour de départ pour un déplacement d'une journée). */
    public function getLastDay(): ?\DateTimeImmutable { return $this->endsOn ?? $this->startsOn; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self
    {
        $this->description = ($description === null || trim(strip_tags($description)) === '') ? null : $description;
        return $this;
    }

    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): self { $this->published = $published; return $this; }

    /** @return list<int> */
    public function getNotifiedUserIds(): array { return array_values(array_map('intval', $this->notifiedUserIds)); }
    /** @param list<int> $ids */
    public function setNotifiedUserIds(array $ids): self
    {
        $this->notifiedUserIds = array_values(array_unique(array_map('intval', $ids)));
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, User> */
    public function getParticipants(): Collection { return $this->participants; }

    public function addParticipant(User $user): self
    {
        if (!$this->participants->contains($user)) {
            $this->participants->add($user);
        }
        return $this;
    }

    public function removeParticipant(User $user): self
    {
        $this->participants->removeElement($user);
        return $this;
    }

    public function hasParticipant(User $user): bool
    {
        foreach ($this->participants as $p) {
            if ($p->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

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

    /** @return Collection<int, Article> */
    public function getArticles(): Collection { return $this->articles; }
}
