<?php

namespace App\Entity;

use App\Repository\InvoiceSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètres facturation — singleton (une seule ligne en base). Contient
 * les infos réutilisées à chaque génération de facture d'adhésion :
 * adresse du club, nom du président, image de sa signature manuscrite.
 */
#[ORM\Entity(repositoryClass: InvoiceSettingsRepository::class)]
#[ORM\Table(name: 'invoice_settings')]
#[ORM\HasLifecycleCallbacks]
class InvoiceSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $clubName = 'Triathlon Toulouse Métropole';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $clubAddress = '';

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $presidentName = '';

    /**
     * Nom du fichier de la signature (dans var/uploads/invoice-signatures/).
     * NULL = pas de signature uploadée (la facture affichera juste le nom).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signatureFilename = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $legalFooter = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getClubName(): string { return $this->clubName; }
    public function setClubName(string $n): self { $this->clubName = $n; return $this; }
    public function getClubAddress(): string { return $this->clubAddress; }
    public function setClubAddress(string $a): self { $this->clubAddress = $a; return $this; }
    public function getPresidentName(): string { return $this->presidentName; }
    public function setPresidentName(string $n): self { $this->presidentName = $n; return $this; }
    public function getSignatureFilename(): ?string { return $this->signatureFilename; }
    public function setSignatureFilename(?string $f): self { $this->signatureFilename = $f; return $this; }
    public function getLegalFooter(): ?string { return $this->legalFooter; }
    public function setLegalFooter(?string $f): self { $this->legalFooter = $f; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return 'Paramètres facturation'; }
}
