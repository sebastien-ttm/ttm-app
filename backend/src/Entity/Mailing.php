<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Repository\MailingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Mailing groupé envoyé par e-mail aux adhérents depuis le backend.
 *
 * Cycle de vie : brouillon → en cours (envoi par lots espacés, voir
 * SendMailingBatchMessageHandler) → terminé. Un envoi peut être mis en pause
 * (manuellement, ou automatiquement après plusieurs échecs d'affilée) puis
 * repris, ou annulé. Les destinataires sont figés au lancement
 * (MailingRecipient), la désinscription d'un adhérent étant revérifiée avant
 * chaque envoi.
 *
 * Audience : audience[] (profils) vide = tous ; includeExternal ajoute les
 * comptes externes (parents non licenciés, amis du club).
 *
 * Placeholders du corps (remplacés par un simple str_replace à l'envoi, valeurs
 * échappées) : {{ prenom }} et {{ nom }}.
 */
#[ORM\Entity(repositoryClass: MailingRepository::class)]
#[ORM\Table(name: 'mailing')]
#[ORM\Index(name: 'idx_mailing_status', columns: ['status'])]
#[ORM\Index(name: 'idx_mailing_created_by', columns: ['created_by_id'])]
#[ORM\HasLifecycleCallbacks]
class Mailing
{
    use AudienceAwareTrait;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Le sujet est obligatoire.')]
    #[Assert\Length(max: 200)]
    private string $subject = '';

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le contenu est obligatoire.')]
    private string $bodyHtml = '';

    /** Ajoute les comptes externes (parents non licenciés, amis du club) à l'audience. */
    #[ORM\Column(options: ['default' => false])]
    private bool $includeExternal = false;

    /** Adresse où arrivent les réponses (l'expéditeur est « noreply »). */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $replyTo = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_DRAFT;

    /** Information affichée à l'admin (pause automatique, attente du quota…). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $statusNote = null;

    /**
     * Jeton de la chaîne d'envoi courante : chaque message de lot le porte et un
     * message dont le jeton n'est plus le bon est ignoré, ce qui évite deux chaînes
     * d'envoi simultanées (relance manuelle pendant qu'un lot différé est en attente).
     */
    #[ORM\Column(length: 32, options: ['default' => ''])]
    private string $runToken = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Un mailing lancé n'est plus modifiable (le contenu partirait en deux versions). */
    #[Assert\Callback]
    public function validateEditable(ExecutionContextInterface $context): void
    {
        if ($this->id !== null && !$this->isDraft()) {
            $context->buildViolation('Ce mailing a déjà été lancé : il n\'est plus modifiable.')
                ->atPath('subject')
                ->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): self { $this->subject = trim($subject); return $this; }

    public function getBodyHtml(): string { return $this->bodyHtml; }
    public function setBodyHtml(string $bodyHtml): self { $this->bodyHtml = $bodyHtml; return $this; }

    public function isIncludeExternal(): bool { return $this->includeExternal; }
    public function setIncludeExternal(bool $includeExternal): self { $this->includeExternal = $includeExternal; return $this; }

    public function getReplyTo(): ?string { return $this->replyTo; }
    public function setReplyTo(?string $replyTo): self
    {
        $replyTo = $replyTo !== null ? trim($replyTo) : null;
        $this->replyTo = $replyTo !== '' ? $replyTo : null;
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function getStatusNote(): ?string { return $this->statusNote; }

    public function getRunToken(): string { return $this->runToken; }
    public function setRunToken(string $runToken): self { $this->runToken = $runToken; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $createdBy): self { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isSending(): bool { return $this->status === self::STATUS_SENDING; }
    public function isPaused(): bool { return $this->status === self::STATUS_PAUSED; }
    public function isDone(): bool { return $this->status === self::STATUS_DONE; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }

    /** Envoi terminé d'une façon ou d'une autre (aucun lot à venir). */
    public function isFinished(): bool { return $this->isDone() || $this->isCancelled(); }

    public function markSending(?string $note = null): self
    {
        $this->status = self::STATUS_SENDING;
        $this->statusNote = $note;
        $this->startedAt ??= new \DateTimeImmutable();
        $this->finishedAt = null;
        return $this;
    }

    public function markPaused(string $note): self
    {
        $this->status = self::STATUS_PAUSED;
        $this->statusNote = self::shorten($note);
        return $this;
    }

    public function markDone(): self
    {
        $this->status = self::STATUS_DONE;
        $this->statusNote = null;
        $this->finishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markCancelled(): self
    {
        $this->status = self::STATUS_CANCELLED;
        $this->statusNote = null;
        $this->finishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setStatusNote(?string $note): self
    {
        $this->statusNote = $note !== null ? self::shorten($note) : null;
        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** @return array<string, string> code => libellé */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Brouillon',
            self::STATUS_SENDING => 'Envoi en cours',
            self::STATUS_PAUSED => 'En pause',
            self::STATUS_DONE => 'Terminé',
            self::STATUS_CANCELLED => 'Annulé',
        ];
    }

    public function __toString(): string
    {
        return $this->subject !== '' ? $this->subject : 'Mailing';
    }

    private static function shorten(string $text): string
    {
        return mb_strlen($text) > 255 ? mb_substr($text, 0, 252).'...' : $text;
    }
}
