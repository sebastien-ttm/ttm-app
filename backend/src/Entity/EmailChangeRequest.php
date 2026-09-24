<?php

namespace App\Entity;

use App\Repository\EmailChangeRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de changement d'adresse e-mail en libre-service. Le lien de
 * confirmation part vers l'adresse ACTUELLE du compte (preuve que
 * l'auteur de la demande contrôle bien la boîte en cours, même si sa
 * session a été volée) ; l'adresse ne change qu'après clic sur ce lien.
 *
 * Même modèle que MagicLinkToken : seul le hash SHA-256 du jeton est
 * stocké, le jeton en clair n'existe que dans l'e-mail.
 */
#[ORM\Entity(repositoryClass: EmailChangeRequestRepository::class)]
#[ORM\Table(name: 'email_change_request')]
#[ORM\Index(name: 'idx_email_change_user', columns: ['user_id'])]
class EmailChangeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $newEmail;

    #[ORM\Column(length: 255, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $newEmail, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->newEmail = $newEmail;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getNewEmail(): string { return $this->newEmail; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function isUsable(): bool
    {
        return $this->usedAt === null && $this->expiresAt > new \DateTimeImmutable();
    }
}
