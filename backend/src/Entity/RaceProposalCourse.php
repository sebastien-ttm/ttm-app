<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Course (épreuve) d'une compétition proposée : « Triathlon M », « Trail 25 km »… Ajoutée par n'importe quel adhérent ;
 * supprimable par celui qui l'a ajoutée ou par l'auteur de la compétition.
 */
#[ORM\Entity]
#[ORM\Table(name: 'race_proposal_course')]
#[ORM\Index(name: 'idx_race_proposal_course_proposal', columns: ['proposal_id'])]
class RaceProposalCourse
{
    public const NAME_MAX = 120;
    public const DETAILS_MAX = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RaceProposal::class, inversedBy: 'courses')]
    #[ORM\JoinColumn(name: 'proposal_id', nullable: false, onDelete: 'CASCADE')]
    private RaceProposal $proposal;

    /** Qui a ajouté la course (null si ce compte a été supprimé depuis). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /** Jour de la course, compris dans les dates de la compétition ; null = non précisé. */
    #[ORM\Column(name: 'course_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $courseDate;

    /** Précisions libres : distances, horaire de départ, format (relais…). */
    #[ORM\Column(length: self::DETAILS_MAX, nullable: true)]
    private ?string $details;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Adhérents qui font cette course. Y participer vaut présence à la compétition (vote « Intéressé(e) / Je suis
     * inscrit(e) ») ; retirer sa présence à la compétition retire aussi ses courses.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'race_proposal_course_participant')]
    #[ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $participants;

    public function __construct(RaceProposal $proposal, User $author, string $name, ?\DateTimeImmutable $courseDate, ?string $details)
    {
        $this->proposal = $proposal;
        $this->author = $author;
        $this->name = $name;
        $this->courseDate = $courseDate;
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
    }

    /** @return Collection<int, User> */
    public function getParticipants(): Collection { return $this->participants; }

    public function hasParticipant(User $user): bool
    {
        foreach ($this->participants as $p) {
            if ($p->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    public function addParticipant(User $user): self
    {
        if (!$this->hasParticipant($user)) {
            $this->participants->add($user);
        }
        return $this;
    }

    public function removeParticipant(User $user): self
    {
        foreach ($this->participants as $p) {
            if ($p->getId() === $user->getId()) {
                $this->participants->removeElement($p);
            }
        }
        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getProposal(): RaceProposal { return $this->proposal; }
    public function getAuthor(): ?User { return $this->author; }
    public function getName(): string { return $this->name; }
    public function getCourseDate(): ?\DateTimeImmutable { return $this->courseDate; }
    public function getDetails(): ?string { return $this->details; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
