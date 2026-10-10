<?php

namespace App\Entity;

use App\Repository\WebPushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Abonnement aux notifications push d'un navigateur / d'une appli web
 * installée (Web Push, RFC 8030). Un adhérent peut en avoir plusieurs
 * (téléphone, ordinateur…). L'`endpoint` est l'adresse du service push du
 * navigateur ; il est unique et identifie l'appareil.
 */
#[ORM\Entity(repositoryClass: WebPushSubscriptionRepository::class)]
#[ORM\Table(name: 'web_push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_web_push_endpoint', columns: ['endpoint_hash'])]
#[ORM\Index(name: 'idx_web_push_user', columns: ['user_id'])]
class WebPushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    /** SHA-256 de l'endpoint : sert de clé d'unicité (l'endpoint peut être long). */
    #[ORM\Column(length: 64)]
    private string $endpointHash;

    /** Clé publique ECDH du navigateur (65 octets, base64url). */
    #[ORM\Column(length: 128)]
    private string $p256dh;

    /** Secret d'authentification du navigateur (16 octets, base64url). */
    #[ORM\Column(length: 64)]
    private string $authSecret;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(User $user, string $endpoint, string $p256dh, string $authSecret, ?string $userAgent)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->endpointHash = self::hashEndpoint($endpoint);
        $this->p256dh = $p256dh;
        $this->authSecret = $authSecret;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getP256dh(): string { return $this->p256dh; }
    public function getAuthSecret(): string { return $this->authSecret; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }

    /** Réabonnement (même appareil) : reprend les clés et rattache l'appareil à l'adhérent connecté. */
    public function refresh(User $user, string $p256dh, string $authSecret, ?string $userAgent): void
    {
        $this->user = $user;
        $this->p256dh = $p256dh;
        $this->authSecret = $authSecret;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : $this->userAgent;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
    }
}
