<?php

namespace App\Entity;

use App\Repository\EventCheckInRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Présence réelle d'un adhérent à un événement, émargée sur place
 * depuis le backend. Distincte du vote (EventAttendance), qui n'est
 * qu'une intention : on peut avoir voté « présent » et ne pas venir,
 * ou venir sans avoir voté.
 */
#[ORM\Entity(repositoryClass: EventCheckInRepository::class)]
#[ORM\Table(name: 'event_check_in')]
#[ORM\UniqueConstraint(name: 'uniq_check_in_event_user', columns: ['event_id', 'user_id'])]
class EventCheckIn
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Personne qui a émargé. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $checkedBy;

    #[ORM\Column]
    private \DateTimeImmutable $checkedAt;

    public function __construct(Event $event, User $user, ?User $checkedBy)
    {
        $this->event = $event;
        $this->user = $user;
        $this->checkedBy = $checkedBy;
        $this->checkedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEvent(): Event { return $this->event; }
    public function getUser(): User { return $this->user; }
    public function getCheckedBy(): ?User { return $this->checkedBy; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
}
