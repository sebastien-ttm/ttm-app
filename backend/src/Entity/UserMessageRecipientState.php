<?php

namespace App\Entity;

use App\Repository\UserMessageRecipientStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * État individuel d'un destinataire vis-à-vis d'un UserMessage :
 * uniquement l'horodatage d'archivage pour l'instant. Nécessaire pour
 * les messages multi-destinataires (scope=Club → tous les admins,
 * scope=AllTrainers → tous les entraîneurs) où chacun archive dans sa
 * propre boîte indépendamment des autres.
 *
 * Une ligne est créée à la demande (upsert) au premier geste
 * d'archivage. L'absence de ligne = état neutre (non archivé).
 */
#[ORM\Entity(repositoryClass: UserMessageRecipientStateRepository::class)]
#[ORM\Table(name: 'user_message_recipient_state')]
#[ORM\UniqueConstraint(name: 'uniq_umrs_user_message', columns: ['user_id', 'message_id'])]
#[ORM\Index(name: 'idx_umrs_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_umrs_message', columns: ['message_id'])]
class UserMessageRecipientState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: UserMessage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserMessage $message;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(User $user, UserMessage $message)
    {
        $this->user = $user;
        $this->message = $message;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getMessage(): UserMessage { return $this->message; }

    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function setArchivedAt(?\DateTimeImmutable $d): self { $this->archivedAt = $d; return $this; }

    public function isArchived(): bool { return $this->archivedAt !== null; }
}
