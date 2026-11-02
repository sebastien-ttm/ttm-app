<?php

namespace App\Entity;

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

    public function __construct(RaceProposal $proposal, User $author, string $name, ?\DateTimeImmutable $courseDate, ?string $details)
    {
        $this->proposal = $proposal;
        $this->author = $author;
        $this->name = $name;
        $this->courseDate = $courseDate;
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProposal(): RaceProposal { return $this->proposal; }
    public function getAuthor(): ?User { return $this->author; }
    public function getName(): string { return $this->name; }
    public function getCourseDate(): ?\DateTimeImmutable { return $this->courseDate; }
    public function getDetails(): ?string { return $this->details; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
