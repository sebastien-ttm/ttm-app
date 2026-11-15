<?php

namespace App\Entity;

use App\Repository\BoardActionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Action décidée en séance sur un point de l'ordre du jour, confiée à un ou plusieurs porteurs : membres du CoDir
 * ou n'importe quel autre adhérent. Saisie dans le back-office (page « En séance »), avec une échéance facultative ;
 * elle reste « en cours » jusqu'à ce qu'on la clôture. Affichée dans l'appli (en évidence pour ses porteurs) et dans
 * le compte rendu de chaque réunion tant qu'elle est en cours.
 */
#[ORM\Entity(repositoryClass: BoardActionRepository::class)]
#[ORM\Table(name: 'board_action')]
#[ORM\Index(name: 'idx_board_action_closed_at', columns: ['closed_at'])]
class BoardAction
{
    public const TEXT_MAX = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BoardAgendaItem::class, inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BoardAgendaItem $item;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::TEXT_MAX)]
    private string $text = '';

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'board_action_assignee')]
    #[ORM\JoinColumn(name: 'action_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $assignees;

    /** Échéance (jour), facultative. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    /** Date de clôture : null tant que l'action est en cours. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(BoardAgendaItem $item, string $text)
    {
        $this->item = $item;
        $this->setText($text);
        $this->assignees = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getItem(): BoardAgendaItem { return $this->item; }

    public function getText(): string { return $this->text; }
    public function setText(string $text): self { $this->text = mb_substr(trim($text), 0, self::TEXT_MAX); return $this; }

    /** @return Collection<int, User> */
    public function getAssignees(): Collection { return $this->assignees; }

    /**
     * Remplace les porteurs de l'action.
     *
     * @param iterable<User> $users
     */
    public function setAssignees(iterable $users): self
    {
        $this->assignees->clear();
        foreach ($users as $user) {
            if (!$this->assignees->contains($user)) {
                $this->assignees->add($user);
            }
        }
        return $this;
    }

    public function isAssignedTo(User $user): bool
    {
        foreach ($this->assignees as $assignee) {
            if ($assignee->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Noms des porteurs, dans l'ordre alphabétique (compte rendu, appli).
     *
     * @return list<string>
     */
    public function getAssigneeNames(): array
    {
        $names = array_map(static fn (User $u) => $u->getFullName(), $this->assignees->toArray());
        sort($names, \SORT_NATURAL | \SORT_FLAG_CASE);
        return $names;
    }

    public function getDueDate(): ?\DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(?\DateTimeImmutable $d): self { $this->dueDate = $d?->setTime(0, 0); return $this; }

    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function isClosed(): bool { return $this->closedAt !== null; }

    /** Clôture l'action (ou la rouvre) : la date de clôture n'est posée qu'une fois. */
    public function setClosed(bool $closed): self
    {
        if ($closed && $this->closedAt === null) {
            $this->closedAt = new \DateTimeImmutable();
        } elseif (!$closed) {
            $this->closedAt = null;
        }
        return $this;
    }

    /** En cours et échéance dépassée (le jour de l'échéance n'est pas encore en retard). */
    public function isOverdue(): bool
    {
        return $this->closedAt === null && $this->dueDate !== null && $this->dueDate < new \DateTimeImmutable('today');
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
