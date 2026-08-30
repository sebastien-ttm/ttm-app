<?php

namespace App\Entity;

use App\Repository\UserSeasonMembershipRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace l'adhésion d'un utilisateur à une saison d'entraînement donnée,
 * pour statistiques historiques et pour distinguer les anciens des nouveaux
 * adhérents à l'import CSV FFTri.
 *
 * Une ligne par (user, season). Créée/mise à jour au moment d'un import
 * CSV où la saison est explicitement sélectionnée par l'admin.
 *
 * Snapshots (statutLicence, typeLicence, categorieAge) figés au moment de
 * l'import : reflètent la situation FFTri de l'user à l'époque, restent
 * consultables même si l'user actuel change ces valeurs plus tard.
 */
#[ORM\Entity(repositoryClass: UserSeasonMembershipRepository::class)]
#[ORM\Table(name: 'user_season_membership')]
#[ORM\UniqueConstraint(name: 'uniq_user_season', columns: ['user_id', 'season_id'])]
#[ORM\Index(name: 'idx_usm_season', columns: ['season_id'])]
class UserSeasonMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: TrainingSeason::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingSeason $season;

    #[ORM\Column]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $statutLicence = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $typeLicence = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categorieAge = null;

    /**
     * Mode de paiement pour cette adhésion. CB par défaut, modifiable par
     * l'admin (formulaire édition user ou action dédiée facturation).
     */
    #[ORM\Column(length: 24, options: ['default' => 'cb'])]
    private string $paymentType = 'cb';

    /** Horodatage de dernière génération/envoi de facture. NULL = jamais. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invoicedAt = null;

    public function __construct(User $user, TrainingSeason $season)
    {
        $this->user = $user;
        $this->season = $season;
        $this->importedAt = new \DateTimeImmutable();
    }

    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getSeason(): TrainingSeason { return $this->season; }
    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getStatutLicence(): ?string { return $this->statutLicence; }
    public function setStatutLicence(?string $s): self { $this->statutLicence = $s; return $this; }
    public function getTypeLicence(): ?string { return $this->typeLicence; }
    public function setTypeLicence(?string $s): self { $this->typeLicence = $s; return $this; }
    public function getCategorieAge(): ?string { return $this->categorieAge; }
    public function setCategorieAge(?string $s): self { $this->categorieAge = $s; return $this; }
    public function getPaymentType(): string { return $this->paymentType; }
    public function setPaymentType(string $t): self { $this->paymentType = $t; return $this; }
    public function getInvoicedAt(): ?\DateTimeImmutable { return $this->invoicedAt; }
    public function markInvoiced(): self { $this->invoicedAt = new \DateTimeImmutable(); return $this; }
}
