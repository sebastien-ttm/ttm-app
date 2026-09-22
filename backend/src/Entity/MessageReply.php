<?php

namespace App\Entity;

use App\Repository\MessageReplyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tour de conversation au-delà du 2e échange (body → reply). Une fois
 * qu'un UserMessage a reçu sa réponse verrouillée (reply/repliedAt),
 * la conversation continue sans limite via des MessageReply
 * successifs, postés indifféremment par l'expéditeur ou par n'importe
 * quel viewer éligible côté destinataire (admin/entraîneur concerné).
 *
 * `author` (au lieu de re-déduire « expéditeur vs destinataire ») trace
 * précisément qui a écrit chaque tour — utile pour les scopes
 * multi-destinataires (club, all_trainers) où plusieurs personnes
 * peuvent intervenir côté « destinataire ».
 */
#[ORM\Entity(repositoryClass: MessageReplyRepository::class)]
#[ORM\Table(name: 'message_reply')]
#[ORM\Index(name: 'idx_message_reply_message', columns: ['message_id'])]
class MessageReply
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserMessage::class, inversedBy: 'threadReplies')]
    #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
    private UserMessage $message;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 5000)]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Horodate l'envoi de la notification email à l'autre partie.
     * Idempotence par tour (contrairement à senderRepliedNotifiedAt
     * sur UserMessage, qui ne couvrait que le tour #2).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    public function __construct(UserMessage $message, User $author, string $content)
    {
        $this->message = $message;
        $this->author = $author;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMessage(): UserMessage { return $this->message; }
    public function getAuthor(): User { return $this->author; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getNotifiedAt(): ?\DateTimeImmutable { return $this->notifiedAt; }
    public function setNotifiedAt(?\DateTimeImmutable $d): self { $this->notifiedAt = $d; return $this; }
}
