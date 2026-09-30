<?php

namespace App\Entity;

use App\Repository\EventCarpoolOfferRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Proposition de covoiturage sur un événement DU CALENDRIER ou une
 * proposition de course : soit un conducteur qui propose des places
 * (seatsAvailable + bikeSlots), soit un passager qui demande une
 * place. Une seule ligne par (user, sujet) — l'user choisit son rôle
 * et peut le changer via édition/suppression.
 *
 * Le sujet est SOIT un événement (`event`) SOIT une proposition de
 * course (`raceProposal`) — exactement l'un des deux est renseigné
 * (voir validateSubject()), même principe que MarketplaceConversation
 * pour la bourse aux équipements/dossards.
 *
 * Pas de messagerie interne : la mise en relation passe par WhatsApp
 * (le n° de téléphone étant récupéré du profil de chaque participant).
 */
#[ORM\Entity(repositoryClass: EventCarpoolOfferRepository::class)]
#[ORM\Table(name: 'event_carpool_offer')]
#[ORM\UniqueConstraint(name: 'uniq_carpool_user_event', columns: ['user_id', 'event_id'])]
#[ORM\UniqueConstraint(name: 'uniq_carpool_user_race', columns: ['user_id', 'race_proposal_id'])]
#[ORM\Index(name: 'idx_carpool_event', columns: ['event_id'])]
#[ORM\Index(name: 'idx_carpool_race', columns: ['race_proposal_id'])]
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
    #[ORM\JoinColumn(name: 'event_id', nullable: true, onDelete: 'CASCADE')]
    private ?Event $event = null;

    #[ORM\ManyToOne(targetEntity: RaceProposal::class)]
    #[ORM\JoinColumn(name: 'race_proposal_id', nullable: true, onDelete: 'CASCADE')]
    private ?RaceProposal $raceProposal = null;

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

    /**
     * $subject nullable uniquement pour permettre à l'admin de créer une
     * ligne vierge depuis EasyAdmin (EventCarpoolOfferCrudController
     * pré-remplit $user avec l'admin courant et laisse $subject à null —
     * voir validateSubject() pour la validation avant sauvegarde). Les
     * appelants API (CarpoolController) fournissent toujours un sujet.
     */
    public function __construct(User $user, Event|RaceProposal|null $subject = null, string $role = self::ROLE_PASSENGER)
    {
        $this->user = $user;
        if ($subject instanceof Event) {
            $this->event = $subject;
        } elseif ($subject instanceof RaceProposal) {
            $this->raceProposal = $subject;
        }
        $this->role = in_array($role, self::ROLES, true) ? $role : self::ROLE_PASSENGER;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
    public function getEvent(): ?Event { return $this->event; }
    public function setEvent(?Event $e): self { $this->event = $e; return $this; }
    public function getRaceProposal(): ?RaceProposal { return $this->raceProposal; }
    public function setRaceProposal(?RaceProposal $r): self { $this->raceProposal = $r; return $this; }

    /** 'event' ou 'race' — selon lequel des deux sujets possibles est renseigné. */
    public function getSubjectKind(): string
    {
        return $this->event !== null ? 'event' : 'race';
    }

    /** Libellé affiché du sujet, pour l'admin (liste/détail). */
    public function getSubjectLabel(): string
    {
        if ($this->event !== null) {
            return sprintf('📅 %s (%s)', $this->event->getTitle(), $this->event->getStartsAt()->format('d/m/Y'));
        }
        if ($this->raceProposal !== null) {
            return sprintf('🏁 %s (%s)', $this->raceProposal->getName(), $this->raceProposal->getRaceDate()->format('d/m/Y'));
        }
        return '—';
    }

    /**
     * Exactement un sujet (event XOR raceProposal) doit être renseigné —
     * pas de contrainte CHECK en base, validée ici (cohérent avec
     * MarketplaceConversation qui applique la même règle en amont, à la
     * construction plutôt qu'en édition admin).
     */
    #[Assert\Callback]
    public function validateSubject(ExecutionContextInterface $context): void
    {
        $count = ($this->event !== null ? 1 : 0) + ($this->raceProposal !== null ? 1 : 0);
        if ($count !== 1) {
            $context->buildViolation('Choisissez un événement OU une proposition de course (l\'un des deux, pas les deux).')
                ->atPath('event')
                ->addViolation();
        }
    }

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
