<?php

namespace App\Entity;

use App\Repository\CapDistributionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Remise d'un bonnet de bain individuel du club à un adhérent, émargée
 * par un entraîneur ou un admin. Un adhérent reçoit normalement un seul
 * bonnet ; une nouvelle ligne trace un remplacement (perdu, abîmé).
 */
#[ORM\Entity(repositoryClass: CapDistributionRepository::class)]
#[ORM\Table(name: 'cap_distribution')]
#[ORM\Index(name: 'idx_cap_distribution_user', columns: ['user_id', 'distributed_at'])]
class CapDistribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Entraîneur ou admin qui a remis le bonnet. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $distributedBy;

    #[ORM\Column]
    private \DateTimeImmutable $distributedAt;

    public function __construct(User $user, ?User $distributedBy)
    {
        $this->user = $user;
        $this->distributedBy = $distributedBy;
        $this->distributedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getDistributedBy(): ?User { return $this->distributedBy; }
    public function getDistributedAt(): \DateTimeImmutable { return $this->distributedAt; }
}
