<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tarif d'un événement à inscription payante (paiement HelloAsso Checkout) : « Adulte 25 € », « Jeune 15 € »,
 * « Repas 10 € »… L'adhérent en choisit un ou plusieurs, avec une quantité (voir EventOrder).
 *
 * - `audience` (profils, comme le ciblage des contenus) : vide = proposé à tous ; sinon, seuls les adhérents ayant l'un de
 *   ces profils voient et peuvent choisir ce tarif (ex. « Licencié TTM » réservé au profil Sénior).
 * - `maxPlaces` : quantité maximale vendable pour ce tarif (commandes payées + commandes en attente non expirées) ;
 *   null = illimité.
 * - Un tarif à 0 € est possible (option gratuite) : une commande dont le total est nul n'ouvre pas HelloAsso.
 *
 * Supprimer un tarif ne touche pas aux commandes déjà passées : leurs lignes gardent le libellé et le prix d'origine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_tariff')]
#[ORM\Index(name: 'idx_event_tariff_event', columns: ['event_id'])]
class EventTariff
{
    use AudienceAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'tariffs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Event $event = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Indiquez le libellé du tarif.')]
    #[Assert\Length(max: 120)]
    private string $label = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $description = null;

    /** Prix unitaire en centimes d'euro, toutes taxes comprises. */
    #[ORM\Column(name: 'amount_cents')]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(value: 500000, message: 'Montant trop élevé (5 000 € au plus).')]
    private int $amountCents = 0;

    #[ORM\Column(name: 'max_places', nullable: true)]
    #[Assert\Positive(message: 'Le nombre de places doit être positif (vide = illimité).')]
    private ?int $maxPlaces = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEvent(): ?Event { return $this->event; }
    public function setEvent(?Event $event): self { $this->event = $event; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = trim($label); return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = ($description === null || $description === '') ? null : $description;
        return $this;
    }

    public function getAmountCents(): int { return $this->amountCents; }
    /** Accepte un flottant : le champ « Prix » du formulaire (MoneyType, diviseur 100) renvoie des centimes décimaux. */
    public function setAmountCents(int|float|null $cents): self { $this->amountCents = (int) round((float) $cents); return $this; }

    public function getMaxPlaces(): ?int { return $this->maxPlaces; }
    public function setMaxPlaces(?int $maxPlaces): self { $this->maxPlaces = $maxPlaces !== null && $maxPlaces > 0 ? $maxPlaces : null; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** « 25,00 € » — affichage back-office. */
    public function getAmountLabel(): string
    {
        return number_format($this->amountCents / 100, 2, ',', ' ').' €';
    }

    public function __toString(): string
    {
        return $this->label !== '' ? $this->label.' — '.$this->getAmountLabel() : '#'.$this->id;
    }
}
