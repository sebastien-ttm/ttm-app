<?php

namespace App\Entity;

use App\Repository\EventCarpoolOfferRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Proposition de covoiturage sur un événement : soit un conducteur
 * qui propose des places (seatsAvailable + bikeSlots), soit un passager
 * qui demande une place. Une seule ligne par (user, event) — l'user
 * choisit son rôle et peut le changer via édition/suppression.
 *
 * Pas de messagerie interne : la mise en relation passe par WhatsApp
 * (le n° de téléphone étant récupéré du profil de chaque participant).
 */
#[ORM\Entity(repositoryClass: EventCarpoolOfferRepository::class)]
#[ORM\Table(name: 'event_carpool_offer')]
#[ORM\UniqueConstraint(name: 'uniq_carpool_user_event', columns: ['user_id', 'event_id'])]
#[ORM\Index(name: 'idx_carpool_event', columns: ['event_id'])]
class EventCarpoolOffer
{
    public const ROLE_DRIVER = 'driver';
    public const ROLE_PASSENGER = 'passenger';
    public const ROLES = [self::ROLE_DRIVER, self::ROLE_PASSENGER];

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

    /** 'driver' ou 'passenger'. */
    #[ORM\Column(length: 16)]
    private string $role;

    /** Nombre de places conducteur. Null pour un passager. */
    #[ORM\Column(nullable: true)]
    private ?int $seatsAvailable = null;

    /** Nombre de vélos que la voiture peut prendre. Null pour un passager. */
    #[ORM\Column(nullable: true)]
    private ?int $bikeSlots = null;

    /**
     * Le conducteur a marqué sa voiture pleine (surchauffe l'info
     * seatsAvailable côté affichage — l'auto reste visible mais ne
     * peut plus accepter de passager). Null pour un passager.
     */
    #[ORM\Column(nullable: true)]
    private ?bool $isFull = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Event $event, string $role)
    {
        $this->user = $user;
        $this->event = $event;
        $this->role = in_array($role, self::ROLES, true) ? $role : self::ROLE_PASSENGER;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEvent(): Event { return $this->event; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $r): self { if (in_array($r, self::ROLES, true)) { $this->role = $r; } return $this; }
    public function isDriver(): bool { return $this->role === self::ROLE_DRIVER; }
    public function isPassenger(): bool { return $this->role === self::ROLE_PASSENGER; }
    public function getSeatsAvailable(): ?int { return $this->seatsAvailable; }
    public function setSeatsAvailable(?int $n): self { $this->seatsAvailable = $n; return $this; }
    public function getBikeSlots(): ?int { return $this->bikeSlots; }
    public function setBikeSlots(?int $n): self { $this->bikeSlots = $n; return $this; }
    public function isFull(): ?bool { return $this->isFull; }
    public function setIsFull(?bool $b): self { $this->isFull = $b; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
