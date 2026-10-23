<?php

namespace App\Entity;

use App\Repository\StageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Stage du club, créé depuis le backend. Ses participants le retrouvent dans l'appli avec une vue de contexte dédiée.
 *
 * Contrairement à un déplacement (qui regroupe des événements existants), TOUT ce qui compose un stage lui est
 * spécifique : événements, articles, sondages, prises de temps et entraînements portent un lien `stage` ; ils
 * n'apparaissent ni dans le calendrier, ni dans le fil d'actualités, ni dans les sondages, les tests chronométrés
 * ou la semaine d'entraînement généraux, et ne sont accessibles qu'aux participants (le staff garde ses vues).
 */
#[ORM\Entity(repositoryClass: StageRepository::class)]
#[ORM\Table(name: 'stage')]
#[ORM\HasLifecycleCallbacks]
class Stage
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
    #[Assert\NotNull(message: 'Indiquez la date de début.')]
    private ?\DateTimeImmutable $startsOn = null;

    /** Dernier jour du stage ; null = un seul jour. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    /** Informations pratiques (HTML de l'éditeur riche : programme, hébergement, matériel…). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Tant que le stage n'est pas publié, personne ne le voit dans l'appli (brouillon), ni ses éléments dédiés. */
    #[ORM\Column]
    private bool $published = false;

    /**
     * Participants déjà prévenus par notification push (id utilisateur) : un adhérent n'est notifié qu'une fois,
     * quand il est ajouté à un stage publié.
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
    #[ORM\JoinTable(name: 'stage_participant')]
    #[ORM\JoinColumn(name: 'stage_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $participants;

    /**
     * Éléments dédiés : chacun porte un lien `stage` (RESTRICT en base) — supprimer le stage ne doit jamais les
     * rendre publics ni les détruire sans que l'éditeur l'ait décidé.
     *
     * @var Collection<int, Event>
     */
    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'stage')]
    #[ORM\OrderBy(['startsAt' => 'ASC'])]
    private Collection $events;

    /** @var Collection<int, Article> */
    #[ORM\OneToMany(targetEntity: Article::class, mappedBy: 'stage')]
    #[ORM\OrderBy(['publishedAt' => 'DESC', 'createdAt' => 'DESC'])]
    private Collection $articles;

    /** @var Collection<int, Survey> */
    #[ORM\OneToMany(targetEntity: Survey::class, mappedBy: 'stage')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $surveys;

    /** @var Collection<int, PerfTestSession> */
    #[ORM\OneToMany(targetEntity: PerfTestSession::class, mappedBy: 'stage')]
    #[ORM\OrderBy(['date' => 'ASC'])]
    private Collection $perfTestSessions;

    /** @var Collection<int, TrainingSlot> */
    #[ORM\OneToMany(targetEntity: TrainingSlot::class, mappedBy: 'stage')]
    #[ORM\OrderBy(['weekStartsAt' => 'ASC', 'dayOfWeek' => 'ASC', 'startTime' => 'ASC'])]
    private Collection $trainingSlots;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->articles = new ArrayCollection();
        $this->surveys = new ArrayCollection();
        $this->perfTestSessions = new ArrayCollection();
        $this->trainingSlots = new ArrayCollection();
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
            $context->buildViolation('La date de fin ne peut pas précéder la date de début.')
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

    /** Dernier jour effectif (le jour de début pour un stage d'une journée). */
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

    /**
     * Le stage et ses éléments dédiés sont-ils accessibles à cet adhérent ? Stage publié ET adhérent participant :
     * c'est la seule règle d'accès côté appli (la liste des participants tient lieu d'audience).
     */
    public function isOpenTo(?User $user): bool
    {
        return $user !== null && $this->published && $this->hasParticipant($user);
    }

    /** @return Collection<int, Event> */
    public function getEvents(): Collection { return $this->events; }

    /** @return Collection<int, Article> */
    public function getArticles(): Collection { return $this->articles; }

    /** @return Collection<int, Survey> */
    public function getSurveys(): Collection { return $this->surveys; }

    /** @return Collection<int, PerfTestSession> */
    public function getPerfTestSessions(): Collection { return $this->perfTestSessions; }

    /** @return Collection<int, TrainingSlot> */
    public function getTrainingSlots(): Collection { return $this->trainingSlots; }

    /**
     * Getter « virtuel » : sert uniquement à ce qu'EasyAdmin trouve une propriété avant d'appeler formatValue() pour
     * la liste des entraînements sur la page de détail (même principe que Survey::getResponseCount()). La vraie valeur
     * est construite côté CRUD, qui sait générer les liens.
     */
    public function getTrainingSlotsList(): string { return ''; }

    /** Le stage a-t-il encore des éléments dédiés ? (il ne peut alors pas être supprimé) */
    public function hasDedicatedContent(): bool
    {
        return !$this->events->isEmpty()
            || !$this->articles->isEmpty()
            || !$this->surveys->isEmpty()
            || !$this->perfTestSessions->isEmpty()
            || !$this->trainingSlots->isEmpty();
    }
}
