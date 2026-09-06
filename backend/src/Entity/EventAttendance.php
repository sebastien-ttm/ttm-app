<?php

namespace App\Entity;

use App\Enum\AttendanceStatus;
use App\Repository\EventAttendanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vote de présence d'un adhérent à un événement soumis au vote.
 * Une seule ligne par (user, event) — l'unique constraint garantit
 * qu'un adhérent ne peut avoir qu'un statut à la fois.
 */
#[ORM\Entity(repositoryClass: EventAttendanceRepository::class)]
#[ORM\Table(name: 'event_attendance')]
#[ORM\UniqueConstraint(name: 'uniq_attendance_user_event', columns: ['user_id', 'event_id'])]
#[ORM\Index(name: 'idx_attendance_event', columns: ['event_id'])]
class EventAttendance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 16, enumType: AttendanceStatus::class)]
    private AttendanceStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Event $event, AttendanceStatus $status)
    {
        $this->user = $user;
        $this->event = $event;
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEvent(): Event { return $this->event; }
    public function getStatus(): AttendanceStatus { return $this->status; }
    public function setStatus(AttendanceStatus $s): self
    {
        $this->status = $s;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
