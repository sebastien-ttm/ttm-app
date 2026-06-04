<?php

namespace App\Entity;

use App\Repository\UserMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message envoyé par un utilisateur mobile vers « le club » (recipient=null,
 * visible uniquement par les admins) ou vers un entraîneur précis (recipient
 * non-null). Le destinataire peut répondre UNE SEULE FOIS depuis le backend ;
 * la réponse + l'auteur sont affichés à l'expéditeur dans l'app mobile.
 */
#[ORM\Entity(repositoryClass: UserMessageRepository::class)]
#[ORM\Table(name: 'user_message')]
#[ORM\Index(name: 'idx_user_message_sender', columns: ['sender_id'])]
#[ORM\Index(name: 'idx_user_message_recipient', columns: ['recipient_id'])]
class UserMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Expéditeur — utilisateur mobile. Jamais null. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $sender;

    /**
     * Destinataire. Null = adressé « au club » (visible aux admins uniquement).
     * Non null = adressé à un entraîneur précis.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recipient = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le message ne peut pas être vide.')]
    #[Assert\Length(max: 5000)]
    private string $body = '';

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    /** Réponse du destinataire. Verrouillé après première écriture. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $reply = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $repliedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $repliedAt = null;

    /**
     * Horodate la dispatch de la notification « nouveau message » aux
     * destinataires (admins ou entraîneur ciblé). Garantit l'idempotence
     * en cas de retry de la queue Messenger.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recipientsNotifiedAt = null;

    /**
     * Horodate la dispatch de la notification de réponse à l'expéditeur.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $senderRepliedNotifiedAt = null;

    public function __construct()
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSender(): User { return $this->sender; }
    public function setSender(User $u): self { $this->sender = $u; return $this; }

    public function getRecipient(): ?User { return $this->recipient; }
    public function setRecipient(?User $u): self { $this->recipient = $u; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $s): self { $this->subject = $s !== null ? trim($s) ?: null : null; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $b): self { $this->body = $b; return $this; }

    public function getSentAt(): \DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(\DateTimeImmutable $d): self { $this->sentAt = $d; return $this; }

    public function getReply(): ?string { return $this->reply; }

    /**
     * Setter brut, requis par Symfony Form pour binder le champ d'édition.
     * Ne porte AUCUNE garantie « une seule fois » : c'est le CRUD admin
     * (UserMessageCrudController::updateEntity) qui rejette les mutations
     * post-réponse en rechargeant l'entité depuis la BDD, et qui crédite
     * l'auteur + l'horodatage via setReplyOnce() ci-dessous.
     */
    public function setReply(?string $reply): self
    {
        $this->reply = $reply;
        return $this;
    }

    /**
     * Pose la réponse + horodate + crédite l'auteur. NO-OP si déjà répondu
     * (règle « une seule réponse »). Renvoie true si la réponse a été acceptée.
     */
    public function setReplyOnce(?string $reply, User $author): bool
    {
        if ($this->repliedAt !== null) {
            return false;
        }
        $clean = $reply !== null ? trim($reply) : '';
        if ($clean === '') {
            return false;
        }
        $this->reply = $clean;
        $this->repliedBy = $author;
        $this->repliedAt = new \DateTimeImmutable();
        return true;
    }

    public function getRepliedBy(): ?User { return $this->repliedBy; }
    public function getRepliedAt(): ?\DateTimeImmutable { return $this->repliedAt; }
    public function hasReply(): bool { return $this->repliedAt !== null; }

    public function getRecipientsNotifiedAt(): ?\DateTimeImmutable { return $this->recipientsNotifiedAt; }
    public function setRecipientsNotifiedAt(?\DateTimeImmutable $d): self { $this->recipientsNotifiedAt = $d; return $this; }

    public function getSenderRepliedNotifiedAt(): ?\DateTimeImmutable { return $this->senderRepliedNotifiedAt; }
    public function setSenderRepliedNotifiedAt(?\DateTimeImmutable $d): self { $this->senderRepliedNotifiedAt = $d; return $this; }

    /**
     * Cible humainement lisible pour les vues (« Le club » ou nom de l'entraîneur).
     */
    public function getRecipientLabel(): string
    {
        return $this->recipient?->getFullName() ?? 'Le club';
    }

    public function __toString(): string
    {
        $who = $this->recipient?->getFullName() ?? 'club';
        $when = $this->sentAt->format('d/m/Y H:i');
        return sprintf('De %s → %s · %s', $this->sender->getFullName(), $who, $when);
    }
}
