<?php

namespace App\Entity;

use App\Enum\EventOrderStatus;
use App\Repository\EventOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inscription payante d'un adhérent à un événement : une ou plusieurs lignes de tarif (EventOrderLine), réglées d'un
 * seul paiement HelloAsso Checkout.
 *
 * Déroulé : l'appli crée la commande « en attente » (les places sont réservées jusqu'à `expiresAt`), le serveur demande
 * un paiement à HelloAsso (`checkoutIntentId`, `checkoutUrl`) et envoie l'adhérent dessus, avec ses informations déjà
 * saisies. La commande passe « payée » quand HelloAsso confirme le paiement — vérifié par un appel authentifié à l'API
 * HelloAsso (jamais sur la foi de l'adresse de retour ni d'une notification) : voir EventOrderService.
 *
 * L'événement et l'adhérent ne sont pas supprimés avec leurs commandes (trace comptable) : leur nom est conservé ici.
 */
#[ORM\Entity(repositoryClass: EventOrderRepository::class)]
#[ORM\Table(name: 'event_order')]
#[ORM\Index(name: 'idx_event_order_event', columns: ['event_id'])]
#[ORM\Index(name: 'idx_event_order_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_event_order_status', columns: ['status'])]
#[ORM\Index(name: 'idx_event_order_checkout', columns: ['checkout_intent_id'])]
class EventOrder
{
    /** Durée pendant laquelle une commande non payée réserve ses places (HelloAsso considère un paiement abandonné après 45 min). */
    public const HOLD_MINUTES = 45;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Event $event;

    /** Titre de l'événement à la commande (l'événement peut être supprimé ensuite). */
    #[ORM\Column(name: 'event_title', length: 200)]
    private string $eventTitle;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    /** Nom de l'adhérent à la commande (le compte peut être supprimé ensuite). */
    #[ORM\Column(name: 'payer_name', length: 200)]
    private string $payerName;

    #[ORM\Column(length: 16, enumType: EventOrderStatus::class)]
    private EventOrderStatus $status = EventOrderStatus::Pending;

    /** Total en centimes d'euro, somme des lignes. */
    #[ORM\Column(name: 'total_cents')]
    private int $totalCents = 0;

    /** Identifiant de la dernière demande de paiement HelloAsso (checkout intent) ; null = pas encore envoyée (ou commande gratuite). */
    #[ORM\Column(name: 'checkout_intent_id', nullable: true)]
    private ?int $checkoutIntentId = null;

    /** Adresse de la page de paiement HelloAsso, valable 15 minutes après sa création. */
    #[ORM\Column(name: 'checkout_url', length: 1000, nullable: true)]
    private ?string $checkoutUrl = null;

    #[ORM\Column(name: 'checkout_created_at', nullable: true)]
    private ?\DateTimeImmutable $checkoutCreatedAt = null;

    /** Identifiant de la commande côté HelloAsso, une fois le paiement confirmé. */
    #[ORM\Column(name: 'helloasso_order_id', nullable: true)]
    private ?int $helloassoOrderId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Fin de la réservation des places pour une commande en attente. */
    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'paid_at', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** Dernière vérification auprès de HelloAsso (évite de l'interroger à chaque lecture). */
    #[ORM\Column(name: 'last_checked_at', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    /**
     * @var Collection<int, EventOrderLine>
     */
    #[ORM\OneToMany(targetEntity: EventOrderLine::class, mappedBy: 'eventOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct(Event $event, User $user)
    {
        $this->event = $event;
        $this->eventTitle = $event->getTitle();
        $this->user = $user;
        $this->payerName = $user->getFullName();
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+'.self::HOLD_MINUTES.' minutes');
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEvent(): ?Event { return $this->event; }
    public function getEventTitle(): string { return $this->eventTitle; }
    public function getUser(): ?User { return $this->user; }
    public function getPayerName(): string { return $this->payerName; }

    public function getStatus(): EventOrderStatus { return $this->status; }
    public function getStatusLabel(): string { return $this->status->label(); }
    public function isPending(): bool { return $this->status === EventOrderStatus::Pending; }
    public function isPaid(): bool { return $this->status === EventOrderStatus::Paid; }

    public function getTotalCents(): int { return $this->totalCents; }
    /** « 40,00 € » — affichage back-office. */
    public function getTotalLabel(): string { return number_format($this->totalCents / 100, 2, ',', ' ').' €'; }

    public function getCheckoutIntentId(): ?int { return $this->checkoutIntentId; }
    public function getCheckoutUrl(): ?string { return $this->checkoutUrl; }
    public function getCheckoutCreatedAt(): ?\DateTimeImmutable { return $this->checkoutCreatedAt; }

    /** Enregistre la demande de paiement envoyée à HelloAsso. */
    public function setCheckout(int $intentId, ?string $url): self
    {
        $this->checkoutIntentId = $intentId;
        $this->checkoutUrl = $url;
        $this->checkoutCreatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** La page de paiement HelloAsso est-elle encore utilisable (15 minutes) ? */
    public function hasLiveCheckoutUrl(?\DateTimeImmutable $now = null): bool
    {
        return $this->checkoutUrl !== null
            && $this->checkoutCreatedAt !== null
            && $this->checkoutCreatedAt->modify('+14 minutes') > ($now ?? new \DateTimeImmutable());
    }

    public function getHelloassoOrderId(): ?int { return $this->helloassoOrderId; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function getLastCheckedAt(): ?\DateTimeImmutable { return $this->lastCheckedAt; }
    public function touchChecked(): self { $this->lastCheckedAt = new \DateTimeImmutable(); return $this; }

    /** Commande en attente dont la réservation des places est échue. */
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->isPending() && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** Prolonge la réservation (nouvelle demande de paiement). */
    public function extendHold(): self
    {
        $this->expiresAt = (new \DateTimeImmutable())->modify('+'.self::HOLD_MINUTES.' minutes');
        return $this;
    }

    /** Paiement confirmé par HelloAsso (ou commande gratuite : `$helloassoOrderId` null). Sans effet si déjà payée. */
    public function markPaid(?int $helloassoOrderId): self
    {
        if ($this->status !== EventOrderStatus::Paid) {
            $this->status = EventOrderStatus::Paid;
            $this->paidAt = new \DateTimeImmutable();
        }
        if ($helloassoOrderId !== null) {
            $this->helloassoOrderId = $helloassoOrderId;
        }
        return $this;
    }

    public function cancel(): self
    {
        if ($this->isPending()) {
            $this->status = EventOrderStatus::Canceled;
        }
        return $this;
    }

    public function abandon(): self
    {
        if ($this->isPending()) {
            $this->status = EventOrderStatus::Abandoned;
        }
        return $this;
    }

    /** @return Collection<int, EventOrderLine> */
    public function getLines(): Collection { return $this->lines; }

    /** Ajoute une ligne et met le total à jour. */
    public function addLine(EventOrderLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $this->totalCents += $line->getUnitAmountCents() * $line->getQuantity();
        }
        return $this;
    }

    /** « 2 × Adulte, 1 × Repas » — intitulé du paiement chez HelloAsso et affichage back-office. */
    public function getLinesSummary(): string
    {
        return implode(', ', array_map(
            static fn (EventOrderLine $l) => $l->getQuantity().' × '.$l->getLabel(),
            $this->lines->toArray(),
        ));
    }

    public function __toString(): string
    {
        return sprintf('#%d — %s', (int) $this->id, $this->eventTitle);
    }
}
