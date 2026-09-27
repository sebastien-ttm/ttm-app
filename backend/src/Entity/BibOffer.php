<?php

namespace App\Entity;

use App\Repository\BibOfferRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bourse aux dossards (onglet Social, à côté de la bourse aux
 * équipements) : un adhérent qui ne peut plus participer à une course
 * propose son/ses dossard(s), en don ou en revente.
 *
 * Prix stocké en centimes (entier) pour éviter les flottants. En
 * revente : prix unitaire et/ou « à négocier » ; en don : ni prix ni
 * négociation. Le contact passe par les discussions de la bourse
 * (MarketplaceConversation::bibOffer).
 *
 * `pausedAt` : même sémantique que MarketplaceListing — null = visible
 * de tous, non-null = masquée (dossards partis, en attente…).
 */
#[ORM\Entity(repositoryClass: BibOfferRepository::class)]
#[ORM\Table(name: 'bib_offer')]
#[ORM\Index(name: 'idx_bib_offer_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_bib_offer_race_date', columns: ['race_date'])]
class BibOffer
{
    public const EXCHANGE_DON = 'don';
    public const EXCHANGE_REVENTE = 'revente';
    public const EXCHANGES = [self::EXCHANGE_DON, self::EXCHANGE_REVENTE];

    public const MAX_QUANTITY = 50;

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
    private string $raceName = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $raceDate;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: self::MAX_QUANTITY)]
    private int $quantity = 1;

    #[ORM\Column(length: 10)]
    private string $exchangeType = self::EXCHANGE_DON;

    /** Prix unitaire en centimes (revente uniquement, null sinon). */
    #[ORM\Column(nullable: true)]
    private ?int $unitPriceCents = null;

    /** Revente : prix à négocier. */
    #[ORM\Column]
    private bool $negotiable = false;

    /** Précisions libres (distance, modalités de transfert…). */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    public function __construct(User $author)
    {
        $this->author = $author;
        $this->raceDate = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAuthor(): User { return $this->author; }

    public function getRaceName(): string { return $this->raceName; }
    public function setRaceName(string $name): self { $this->raceName = trim($name); return $this; }

    public function getRaceDate(): \DateTimeImmutable { return $this->raceDate; }
    public function setRaceDate(\DateTimeImmutable $d): self { $this->raceDate = $d; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $q): self { $this->quantity = $q; return $this; }

    public function getExchangeType(): string { return $this->exchangeType; }
    public function isResale(): bool { return $this->exchangeType === self::EXCHANGE_REVENTE; }

    /**
     * Fixe le type d'échange et le prix de façon cohérente : un don n'a
     * jamais de prix ni de négociation.
     */
    public function setExchange(string $type, ?int $unitPriceCents, bool $negotiable): self
    {
        $this->exchangeType = $type;
        if ($type === self::EXCHANGE_REVENTE) {
            $this->unitPriceCents = $unitPriceCents;
            $this->negotiable = $negotiable;
        } else {
            $this->unitPriceCents = null;
            $this->negotiable = false;
        }
        return $this;
    }

    public function getUnitPriceCents(): ?int { return $this->unitPriceCents; }
    public function isNegotiable(): bool { return $this->negotiable; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self
    {
        $d = $d !== null ? trim($d) : null;
        $this->description = ($d === null || $d === '') ? null : $d;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touchUpdatedAt(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getPausedAt(): ?\DateTimeImmutable { return $this->pausedAt; }
    public function setPausedAt(?\DateTimeImmutable $d): self { $this->pausedAt = $d; return $this; }
    public function isPaused(): bool { return $this->pausedAt !== null; }

    /** « 25,00 € / dossard · à négocier », « Don », « Prix à négocier » — affichage admin / e-mails. */
    public function getPriceLabel(): string
    {
        if (!$this->isResale()) {
            return 'Don';
        }
        if ($this->unitPriceCents === null) {
            return 'Prix à négocier';
        }
        $price = number_format($this->unitPriceCents / 100, 2, ',', ' ').' € / dossard';
        return $this->negotiable ? $price.' · à négocier' : $price;
    }

    public function __toString(): string
    {
        return $this->raceName !== '' ? 'Dossard '.$this->raceName : '#'.$this->id;
    }
}
