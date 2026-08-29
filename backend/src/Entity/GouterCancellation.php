<?php

namespace App\Entity;

use App\Repository\GouterCancellationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marque un mercredi comme annulé (vacances scolaires, compétition, jour
 * férié, etc.). Les positionnements existants restent visibles pour
 * mémoire mais aucun nouveau positionnement n'est possible sur cette date.
 */
#[ORM\Entity(repositoryClass: GouterCancellationRepository::class)]
#[ORM\Table(name: 'gouter_cancellation')]
#[ORM\UniqueConstraint(name: 'uniq_gouter_cancel_date', columns: ['cancelled_date'])]
class GouterCancellation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Date du mercredi annulé (heure ignorée). */
    #[ORM\Column(name: 'cancelled_date', type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private \DateTimeImmutable $cancelledAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $cancelledBy = null;

    public function __construct(\DateTimeImmutable $date, ?User $cancelledBy = null, ?string $reason = null)
    {
        $this->date = $date->setTime(0, 0, 0);
        $this->cancelledBy = $cancelledBy;
        $this->reason = $reason;
        $this->cancelledAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $r): self { $this->reason = $r; return $this; }
    public function getCancelledAt(): \DateTimeImmutable { return $this->cancelledAt; }
    public function getCancelledBy(): ?User { return $this->cancelledBy; }
}
