<?php

namespace App\Entity;

use App\Enum\Profile;
use App\Repository\MembershipFeeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grille tarifaire d'adhésion : (saison × profil × type de licence) → montant.
 * Unique pour ce triplet — l'admin y saisit tous les tarifs applicables
 * pour la saison en cours (Jeune Compétition, Sénior Loisir, Performance…).
 */
#[ORM\Entity(repositoryClass: MembershipFeeRepository::class)]
#[ORM\Table(name: 'membership_fee')]
#[ORM\UniqueConstraint(name: 'uniq_fee_season_profile_type', columns: ['season_id', 'profile', 'type_licence'])]
class MembershipFee
{
    /** Types de licence facturables (Compétition / Loisir / Dirigeant). */
    public const TYPE_COMPETITION = 'Compétition';
    public const TYPE_LOISIR = 'Loisir';
    public const TYPE_DIRIGEANT = 'Dirigeant';
    public const TYPES = [self::TYPE_COMPETITION, self::TYPE_LOISIR, self::TYPE_DIRIGEANT];

    /** Profils tarifaires (Jeune / Performance / Sénior). */
    public const APPLICABLE_PROFILES = [Profile::Jeune->value, Profile::Performance->value, Profile::Senior->value];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingSeason::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingSeason $season;

    #[ORM\Column(length: 24)]
    #[Assert\Choice(callback: 'validProfiles')]
    private string $profile;

    #[ORM\Column(length: 24)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $typeLicence;

    /** Montant en centimes d'euro pour éviter tout arrondi float. */
    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    private int $amountCents = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct() {}

    /** @return list<string> */
    public static function validProfiles(): array { return self::APPLICABLE_PROFILES; }

    public function getId(): ?int { return $this->id; }
    public function getSeason(): TrainingSeason { return $this->season; }
    public function setSeason(TrainingSeason $s): self { $this->season = $s; return $this; }
    public function getProfile(): string { return $this->profile; }
    public function setProfile(string $p): self { $this->profile = $p; return $this; }
    public function getTypeLicence(): string { return $this->typeLicence; }
    public function setTypeLicence(string $t): self { $this->typeLicence = $t; return $this; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $c): self { $this->amountCents = max(0, $c); return $this; }
    public function getAmount(): float { return $this->amountCents / 100; }
    public function setAmount(float $euros): self { $this->amountCents = (int) round($euros * 100); return $this; }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function __toString(): string
    {
        return sprintf('%s %s — %s €', ucfirst($this->profile), $this->typeLicence, number_format($this->getAmount(), 2, ',', ' '));
    }
}
