<?php

namespace App\Entity;

use App\Repository\BoardMeetingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réunion du comité directeur (CoDir). Les membres du CoDir y ajoutent des points à l'ordre du jour depuis
 * l'appli tant qu'elle n'est pas close ; en séance, les réponses / décisions sont saisies dans le back-office.
 * Une fois close (`closedAt`), l'ordre du jour devient le compte rendu, consultable dans l'appli.
 */
#[ORM\Entity(repositoryClass: BoardMeetingRepository::class)]
#[ORM\Table(name: 'board_meeting')]
#[ORM\Index(name: 'idx_board_meeting_scheduled_at', columns: ['scheduled_at'])]
class BoardMeeting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $location = null;

    /** Informations pratiques, invités… (affiché dans l'appli). */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, BoardAgendaItem> */
    #[ORM\OneToMany(targetEntity: BoardAgendaItem::class, mappedBy: 'meeting', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->scheduledAt = (new \DateTimeImmutable('+7 days'))->setTime(20, 0);
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getScheduledAt(): \DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(\DateTimeImmutable $d): self { $this->scheduledAt = $d; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $l): self { $this->location = $l !== null && trim($l) !== '' ? trim($l) : null; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n !== null && trim($n) !== '' ? trim($n) : null; return $this; }

    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }

    public function isClosed(): bool { return $this->closedAt !== null; }

    /** Case « Réunion close » du back-office. */
    public function setClosed(bool $closed): self
    {
        if ($closed && $this->closedAt === null) {
            $this->closedAt = new \DateTimeImmutable();
        } elseif (!$closed) {
            $this->closedAt = null;
        }
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, BoardAgendaItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(BoardAgendaItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
        return $this;
    }

    public function removeItem(BoardAgendaItem $item): self
    {
        $this->items->removeElement($item);
        return $this;
    }

    /**
     * Points triés comme dans le compte rendu : par pôle (ordre officiel) puis par ordre d'ajout.
     *
     * @return list<BoardAgendaItem>
     */
    public function getSortedItems(): array
    {
        $items = $this->items->toArray();
        usort($items, static fn (BoardAgendaItem $a, BoardAgendaItem $b) =>
            [$a->getPole()->order(), $a->getCreatedAt(), $a->getId()] <=> [$b->getPole()->order(), $b->getCreatedAt(), $b->getId()]);
        return $items;
    }

    public function getItemCount(): int { return $this->items->count(); }

    public function getAnsweredCount(): int
    {
        return count(array_filter($this->items->toArray(), static fn (BoardAgendaItem $i) => $i->isAnswered()));
    }

    /** « CoDir du 23/09/2026 ». */
    public function getLabel(): string
    {
        return 'CoDir du '.$this->scheduledAt->format('d/m/Y');
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }
}
