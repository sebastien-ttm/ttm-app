<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'une inscription payante : un tarif choisi, avec sa quantité. Le libellé et le prix unitaire sont figés à la
 * commande (le tarif peut être modifié ou supprimé ensuite sans changer ce qui a été payé).
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_order_line')]
class EventOrderLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EventOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private EventOrder $eventOrder;

    #[ORM\ManyToOne(targetEntity: EventTariff::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EventTariff $tariff;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(name: 'unit_amount_cents')]
    private int $unitAmountCents;

    #[ORM\Column]
    private int $quantity;

    public function __construct(EventOrder $eventOrder, EventTariff $tariff, int $quantity)
    {
        $this->eventOrder = $eventOrder;
        $this->tariff = $tariff;
        $this->label = $tariff->getLabel();
        $this->unitAmountCents = $tariff->getAmountCents();
        $this->quantity = $quantity;
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): EventOrder { return $this->eventOrder; }
    public function getTariff(): ?EventTariff { return $this->tariff; }
    public function getLabel(): string { return $this->label; }
    public function getUnitAmountCents(): int { return $this->unitAmountCents; }
    public function getQuantity(): int { return $this->quantity; }
}
