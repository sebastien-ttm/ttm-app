<?php

namespace App\Entity;

use App\Enum\DevicePlatform;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: 'device_token')]
#[ORM\UniqueConstraint(name: 'uniq_device_token', columns: ['expo_push_token'])]
class DeviceToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'deviceTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $expoPushToken;

    #[ORM\Column(length: 16, enumType: DevicePlatform::class)]
    private DevicePlatform $platform;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $expoPushToken, DevicePlatform $platform)
    {
        $this->user = $user;
        $this->expoPushToken = $expoPushToken;
        $this->platform = $platform;
        $this->lastSeenAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getExpoPushToken(): string { return $this->expoPushToken; }
    public function getPlatform(): DevicePlatform { return $this->platform; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function touch(): void { $this->lastSeenAt = new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
