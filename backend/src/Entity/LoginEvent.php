<?php

namespace App\Entity;

use App\Repository\LoginEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des connexions réussies. Une ligne par connexion,
 * persistée par le LoginRecorder (mobile JWT ou admin form).
 *
 * Complète User.lastLoginAt + User.loginCount qui ne donnent que la
 * dernière connexion + un cumul. Ici on garde l'historique fin pour
 * agréger : connexions par jour, comptes actifs sur une période, etc.
 */
#[ORM\Entity(repositoryClass: LoginEventRepository::class)]
#[ORM\Table(name: 'login_event')]
#[ORM\Index(name: 'idx_login_event_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_login_event_user', columns: ['user_id'])]
class LoginEvent
{
    public const CHANNEL_MOBILE = 'mobile';
    public const CHANNEL_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** 'mobile' (JWT login / magic link) ou 'admin' (form login backend). */
    #[ORM\Column(length: 16)]
    private string $channel;

    public function __construct(User $user, string $channel, ?\DateTimeImmutable $at = null)
    {
        $this->user = $user;
        $this->channel = $channel;
        $this->occurredAt = $at ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getChannel(): string { return $this->channel; }
}
