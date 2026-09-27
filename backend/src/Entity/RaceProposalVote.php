<?php

namespace App\Entity;

use App\Repository\RaceProposalVoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Intérêt d'un adhérent pour une course proposée : « Intéressé(e) » ou
 * « Peut-être ». Pas de ligne = pas d'avis. Un seul vote par adhérent.
 */
#[ORM\Entity(repositoryClass: RaceProposalVoteRepository::class)]
#[ORM\Table(name: 'race_proposal_vote')]
#[ORM\UniqueConstraint(name: 'uniq_race_proposal_vote_user', columns: ['proposal_id', 'user_id'])]
class RaceProposalVote
{
    public const INTERESTED = 'interested';
    public const MAYBE = 'maybe';
    public const STATUSES = [self::INTERESTED, self::MAYBE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RaceProposal::class, inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RaceProposal $proposal;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(RaceProposal $proposal, User $user, string $status)
    {
        $this->proposal = $proposal;
        $this->user = $user;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProposal(): RaceProposal { return $this->proposal; }
    public function getUser(): User { return $this->user; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
