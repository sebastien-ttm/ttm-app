<?php

namespace App\Entity;

use App\Enum\RaceProposalStatus;
use App\Enum\RaceType;
use App\Repository\RaceProposalRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compétition proposée par un adhérent (onglet Social) : un événement d'un ou plusieurs jours, qui peut comporter
 * plusieurs épreuves ou courses. Les autres adhérents indiquent leur intérêt (RaceProposalVote).
 *
 * `captain` = l'auteur se porte capitaine : il prend contact avec
 * l'organisateur et propose une inscription groupée.
 *
 * Modération (triathlons seulement, voir RaceType::requiresModeration) : une proposition de triathlon est d'abord « en
 * attente de validation » (visible de son seul auteur) ; un administrateur la valide (publiée) ou la refuse en indiquant
 * un motif que l'auteur reçoit — voir RaceProposalStatus et App\Service\RaceProposalModerator. Les autres types de course sont publiés dès leur proposition.
 */
#[ORM\Entity(repositoryClass: RaceProposalRepository::class)]
#[ORM\Table(name: 'race_proposal')]
#[ORM\Index(name: 'idx_race_proposal_date', columns: ['race_date'])]
#[ORM\Index(name: 'idx_race_proposal_status', columns: ['status'])]
class RaceProposal
{
    /** Longueur maximale du motif d'un refus. */
    public const MODERATION_NOTE_MAX = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $name = '';

    /** Premier jour de la compétition. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $raceDate;

    /** Dernier jour de la compétition (incluse) ; null = une seule journée. */
    #[ORM\Column(name: 'race_end_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $raceEndDate = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $url = null;

    /** Texte libre de présentation de la course (distance, parcours, contexte…), facultatif. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $captain = false;

    /**
     * Capitaine uniquement : date limite (incluse) pour répondre à la proposition, avant l'inscription groupée.
     * Passée, les adhérents ne peuvent plus changer leur réponse ; le capitaine peut la repousser en modifiant sa
     * proposition. null = pas de date limite.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $registrationDeadline = null;

    /**
     * Covoiturage activé : les adhérents peuvent proposer des places
     * (conducteur) ou demander à en réserver (passager). Défaut FALSE —
     * activation par l'auteur à la création / édition. Même principe
     * que Event::$carpoolingEnabled.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $carpoolingEnabled = false;

    #[ORM\Column(length: 20, enumType: RaceType::class)]
    private RaceType $type = RaceType::Autre;

    /**
     * Statut de modération. Une nouvelle proposition de triathlon est « en attente » (sauf celles d'un administrateur,
     * publiées d'emblée) ; les autres types de course sont publiés d'emblée. Le défaut SQL « validated » ne sert qu'à la
     * migration : les propositions déjà en base restent publiées.
     */
    #[ORM\Column(length: 20, enumType: RaceProposalStatus::class, options: ['default' => 'validated'])]
    private RaceProposalStatus $status = RaceProposalStatus::Pending;

    /** Motif du refus, saisi par l'administrateur et vu de l'auteur seul. Effacé quand la proposition repart en validation ou est validée. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $moderationNote = null;

    /** Première publication (validation) ; null = jamais publiée. Sert au repère « Nouveau » côté appli. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * Rang de publication : strictement supérieur à tous les identifiants et rangs existants au moment de la
     * validation. Les « nouveautés non vues » de l'appli comparent des nombres croissants : une proposition validée
     * tardivement (identifiant ancien) doit quand même compter comme nouvelle, ce que l'identifiant seul ne permet pas.
     */
    #[ORM\Column(nullable: true)]
    private ?int $publishedRank = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, RaceProposalVote>
     */
    #[ORM\OneToMany(targetEntity: RaceProposalVote::class, mappedBy: 'proposal', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $votes;

    /**
     * Courses (épreuves) de la compétition, ajoutées par les adhérents.
     *
     * @var Collection<int, RaceProposalCourse>
     */
    #[ORM\OneToMany(targetEntity: RaceProposalCourse::class, mappedBy: 'proposal', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['courseDate' => 'ASC', 'createdAt' => 'ASC'])]
    private Collection $courses;

    public function __construct(User $author)
    {
        $this->author = $author;
        $this->raceDate = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->votes = new ArrayCollection();
        $this->courses = new ArrayCollection();
    }

    /** @return Collection<int, RaceProposalCourse> */
    public function getCourses(): Collection { return $this->courses; }

    public function addCourse(RaceProposalCourse $course): self
    {
        if (!$this->courses->contains($course)) {
            $this->courses->add($course);
        }
        return $this;
    }

    public function removeCourse(RaceProposalCourse $course): self
    {
        $this->courses->removeElement($course);
        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getAuthor(): User { return $this->author; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getRaceDate(): \DateTimeImmutable { return $this->raceDate; }
    public function setRaceDate(\DateTimeImmutable $d): self { $this->raceDate = $d; return $this; }

    public function getRaceEndDate(): ?\DateTimeImmutable { return $this->raceEndDate; }
    /** Une date de fin égale au premier jour est enregistrée comme « une seule journée » (null). */
    public function setRaceEndDate(?\DateTimeImmutable $d): self
    {
        $this->raceEndDate = $d !== null && $d->format('Y-m-d') === $this->raceDate->format('Y-m-d') ? null : $d;
        return $this;
    }

    /** Dernier jour de la compétition : la date de fin, ou le premier jour pour une compétition d'une journée. */
    public function getLastDay(): \DateTimeImmutable { return $this->raceEndDate ?? $this->raceDate; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): self
    {
        $url = $url !== null ? trim($url) : null;
        $this->url = ($url === null || $url === '') ? null : $url;
        return $this;
    }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = ($description === null || $description === '') ? null : $description;
        return $this;
    }

    public function isCaptain(): bool { return $this->captain; }
    public function setCaptain(bool $captain): self { $this->captain = $captain; return $this; }

    public function getRegistrationDeadline(): ?\DateTimeImmutable { return $this->registrationDeadline; }
    public function setRegistrationDeadline(?\DateTimeImmutable $d): self { $this->registrationDeadline = $d; return $this; }

    /** Date limite de réponse dépassée (capitaine avec date limite) : plus de changement de réponse possible. */
    public function isVotingClosed(?\DateTimeImmutable $today = null): bool
    {
        return $this->captain
            && $this->registrationDeadline !== null
            && ($today ?? new \DateTimeImmutable('today')) > $this->registrationDeadline;
    }

    public function isCarpoolingEnabled(): bool { return $this->carpoolingEnabled; }
    public function setCarpoolingEnabled(bool $v): self { $this->carpoolingEnabled = $v; return $this; }

    public function getType(): RaceType { return $this->type; }
    public function setType(RaceType $type): self { $this->type = $type; return $this; }

    public function getStatus(): RaceProposalStatus { return $this->status; }
    public function setStatus(RaceProposalStatus $status): self { $this->status = $status; return $this; }

    public function isPending(): bool { return $this->status === RaceProposalStatus::Pending; }
    public function isRefused(): bool { return $this->status === RaceProposalStatus::Refused; }
    /** Publiée (validée) : visible de tous, et les adhérents peuvent y répondre (voter, ajouter une course, s'inscrire à une course). */
    public function isPublished(): bool { return $this->status->isPublic(); }

    /** Une proposition non publiée (en attente, refusée) n'est visible que de son auteur. */
    public function isVisibleTo(User $viewer): bool
    {
        return $this->isPublished() || $this->author->getId() === $viewer->getId();
    }

    public function getModerationNote(): ?string { return $this->moderationNote; }
    public function setModerationNote(?string $note): self
    {
        $note = $note !== null ? trim($note) : null;
        $this->moderationNote = ($note === null || $note === '') ? null : $note;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getPublishedRank(): ?int { return $this->publishedRank; }
    /** Enregistre la première publication (sans effet si elle a déjà eu lieu : une nouvelle validation ne « republie » pas). */
    public function markPublished(int $rank): self
    {
        if ($this->publishedAt === null) {
            $this->publishedAt = new \DateTimeImmutable();
            $this->publishedRank = $rank;
        }
        return $this;
    }

    /** Libellé du statut — helper d'affichage EA. */
    public function getStatusLabel(): string { return $this->status->label(); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touchUpdatedAt(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, RaceProposalVote> */
    public function getVotes(): Collection { return $this->votes; }

    /** Libellé du type — helper d'affichage EA. */
    public function getTypeLabel(): string { return $this->type->label(); }

    /** Nombre d'intéressés — helper d'affichage EA. */
    public function getInterestedCount(): int
    {
        return $this->votes->filter(fn (RaceProposalVote $v) => $v->getStatus() === RaceProposalVote::INTERESTED)->count();
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : '#'.$this->id;
    }
}
