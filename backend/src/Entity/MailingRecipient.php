<?php

namespace App\Entity;

use App\Repository\MailingRecipientRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un destinataire d'un mailing : instantané de l'adhérent au moment du
 * lancement (adresse et nom figés) et état de son envoi. L'unicité
 * (mailing, adresse) garantit qu'une adresse partagée (parent et enfant) ne
 * reçoit le mailing qu'une fois.
 */
#[ORM\Entity(repositoryClass: MailingRecipientRepository::class)]
#[ORM\Table(name: 'mailing_recipient')]
#[ORM\Index(name: 'idx_mailing_recipient_status', columns: ['mailing_id', 'status'])]
#[ORM\Index(name: 'idx_mailing_recipient_sent', columns: ['sent_at'])]
#[ORM\Index(name: 'idx_mailing_recipient_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_mailing_recipient_email', columns: ['mailing_id', 'email'])]
class MailingRecipient
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    /** Écarté avant l'envoi (désinscrit ou compte désactivé entre-temps). */
    public const STATUS_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Mailing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mailing $mailing;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 180)]
    private string $email;

    /** « Prénom NOM » au moment du lancement. */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct(Mailing $mailing, User $user)
    {
        $this->mailing = $mailing;
        $this->user = $user;
        $this->email = mb_strtolower(trim($user->getEmail()), 'UTF-8');
        $this->name = trim($user->getPrenom().' '.mb_strtoupper($user->getNom(), 'UTF-8'));
    }

    public function getId(): ?int { return $this->id; }
    public function getMailing(): Mailing { return $this->mailing; }
    public function getUser(): ?User { return $this->user; }
    public function getEmail(): string { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getStatus(): string { return $this->status; }
    public function getError(): ?string { return $this->error; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }

    public function markSent(): self
    {
        $this->status = self::STATUS_SENT;
        $this->error = null;
        $this->sentAt = new \DateTimeImmutable();
        return $this;
    }

    public function markFailed(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error = mb_strlen($error) > 500 ? mb_substr($error, 0, 497).'...' : $error;
        return $this;
    }

    public function markSkipped(string $reason): self
    {
        $this->status = self::STATUS_SKIPPED;
        $this->error = $reason;
        return $this;
    }

    /** @return array<string, string> code => libellé */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'En attente',
            self::STATUS_SENT => 'Envoyé',
            self::STATUS_FAILED => 'Échec',
            self::STATUS_SKIPPED => 'Écarté',
        ];
    }
}
