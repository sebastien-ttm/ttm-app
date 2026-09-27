<?php

namespace App\Entity;

use App\Enum\RaceType;
use App\Repository\RaceProposalRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Course proposée par un adhérent (onglet Social) : les autres
 * adhérents indiquent leur intérêt (RaceProposalVote).
 *
 * `captain` = l'auteur se porte capitaine : il prend contact avec
 * l'organisateur et propose une inscription groupée.
 */
#[ORM\Entity(repositoryClass: RaceProposalRepository::class)]
#[ORM\Table(name: 'race_proposal')]
#[ORM\Index(name: 'idx_race_proposal_date', columns: ['race_date'])]
class RaceProposal
{
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

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $raceDate;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $url = null;

    #[ORM\Column]
    private bool $captain = false;

    #[ORM\Column(length: 20, enumType: RaceType::class)]
    private RaceType $type = RaceType::Autre;

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

    public function __construct(User $author)
    {
        $this->author = $author;
        $this->raceDate = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->votes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAuthor(): User { return $this->author; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getRaceDate(): \DateTimeImmutable { return $this->raceDate; }
    public function setRaceDate(\DateTimeImmutable $d): self { $this->raceDate = $d; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): self
    {
        $url = $url !== null ? trim($url) : null;
        $this->url = ($url === null || $url === '') ? null : $url;
        return $this;
    }

    public function isCaptain(): bool { return $this->captain; }
    public function setCaptain(bool $captain): self { $this->captain = $captain; return $this; }

    public function getType(): RaceType { return $this->type; }
    public function setType(RaceType $type): self { $this->type = $type; return $this; }

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
