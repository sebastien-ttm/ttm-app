<?php

namespace App\Entity;

use App\Enum\MessageScope;
use App\Repository\UserMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message envoyé par un utilisateur mobile vers l'une des 3 cibles :
 *  - scope=Club        : « le club » (visible admins) — recipient=null
 *  - scope=Trainer     : un entraîneur précis         — recipient=User
 *  - scope=AllTrainers : tous les entraîneurs actifs  — recipient=null
 *
 * Le destinataire peut répondre UNE SEULE FOIS ; la réponse + l'auteur
 * sont affichés à l'expéditeur ET aux autres destinataires (pour les
 * scopes multi-destinataires). L'archivage se fait côté expéditeur
 * (senderArchivedAt) et côté destinataire (via UserMessageRecipientState,
 * indépendant par personne).
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
     * Destinataire nommé. Null pour scope=Club et scope=AllTrainers.
     * Non null obligatoirement pour scope=Trainer.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recipient = null;

    /** Portée du message — voir enum MessageScope. */
    #[ORM\Column(length: 20, enumType: MessageScope::class, options: ['default' => 'club'])]
    private MessageScope $scope = MessageScope::Club;

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

    /**
     * Archivage côté expéditeur : le message reste visible dans « Archivés »
     * mais disparaît de la liste courante des envoyés. Indépendant de
     * l'archivage côté destinataires (UserMessageRecipientState).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $senderArchivedAt = null;

    public function __construct()
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSender(): User { return $this->sender; }
    public function setSender(User $u): self { $this->sender = $u; return $this; }

    public function getRecipient(): ?User { return $this->recipient; }
    public function setRecipient(?User $u): self { $this->recipient = $u; return $this; }

    public function getScope(): MessageScope { return $this->scope; }
    public function setScope(MessageScope $s): self { $this->scope = $s; return $this; }

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

    public function getSenderArchivedAt(): ?\DateTimeImmutable { return $this->senderArchivedAt; }
    public function setSenderArchivedAt(?\DateTimeImmutable $d): self { $this->senderArchivedAt = $d; return $this; }
    public function isSenderArchived(): bool { return $this->senderArchivedAt !== null; }

    /**
     * Cible humainement lisible :
     *  - « Le club »              (scope=Club)
     *  - « Tous les entraîneurs » (scope=AllTrainers)
     *  - « Prénom Nom »           (scope=Trainer, recipient renseigné)
     */
    public function getRecipientLabel(): string
    {
        return match ($this->scope) {
            MessageScope::Club => 'Le club',
            MessageScope::AllTrainers => 'Tous les entraîneurs',
            MessageScope::Trainer => $this->recipient?->getFullName() ?? 'Entraîneur inconnu',
        };
    }

    public function __toString(): string
    {
        $when = $this->sentAt->format('d/m/Y H:i');
        return sprintf('De %s → %s · %s', $this->sender->getFullName(), $this->getRecipientLabel(), $when);
    }
}
