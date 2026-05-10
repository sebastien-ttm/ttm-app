<?php

namespace App\Entity;

use App\Repository\CharterAcceptanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharterAcceptanceRepository::class)]
#[ORM\Table(name: 'charter_acceptance')]
#[ORM\UniqueConstraint(name: 'uniq_charter_acceptance_user_charter', columns: ['user_id', 'charter_id'])]
#[ORM\Index(name: 'idx_charter_acceptance_charter', columns: ['charter_id'])]
class CharterAcceptance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ClubCharter::class, inversedBy: 'acceptances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ClubCharter $charter;

    #[ORM\Column]
    private \DateTimeImmutable $acceptedAt;

    /**
     * Adresse IP au moment de l'acceptation, conservée pour audit.
     */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    public function __construct(User $user, ClubCharter $charter, ?string $ipAddress = null)
    {
        $this->user = $user;
        $this->charter = $charter;
        $this->ipAddress = $ipAddress;
        $this->acceptedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getCharter(): ClubCharter { return $this->charter; }
    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
}
