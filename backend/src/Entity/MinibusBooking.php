<?php

namespace App\Entity;

use App\Enum\MinibusBookingStatus;
use App\Enum\MinibusCheckType;
use App\Repository\MinibusBookingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'emprunt du minibus du club (et de sa remorque) par un membre du CoDir. Le gestionnaire du minibus la valide
 * ou la refuse ; une fois validée, l'emprunteur accepte la charte d'utilisation, puis documente l'emprunt par deux états
 * des lieux — au départ, au retour — avec photos et compte rendu (voir MinibusCheck).
 *
 * Les dates sont des jours entiers, bornes comprises (pas d'horaires). Le nom de l'emprunteur et celui de la personne qui
 * a décidé sont conservés tels quels : l'historique reste lisible si un compte disparaît.
 */
#[ORM\Entity(repositoryClass: MinibusBookingRepository::class)]
#[ORM\Table(name: 'minibus_booking')]
#[ORM\Index(name: 'idx_minibus_booking_dates', columns: ['start_date', 'end_date'])]
#[ORM\Index(name: 'idx_minibus_booking_status', columns: ['status'])]
class MinibusBooking
{
    /** Étapes d'un emprunt validé : charte à accepter, état des lieux de départ, de retour, terminé. */
    public const STEP_CHARTER = 'charter';
    public const STEP_DEPARTURE = 'departure';
    public const STEP_RETURN = 'return';
    public const STEP_DONE = 'done';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requester_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requester;

    #[ORM\Column(name: 'requester_name', length: 200)]
    private string $requesterName;

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    /** Raison de l'emprunt, indiquée par le demandeur. */
    #[ORM\Column(type: 'text')]
    private string $reason;

    /** La remorque est-elle aussi demandée ? */
    #[ORM\Column(name: 'with_trailer', options: ['default' => false])]
    private bool $withTrailer = false;

    #[ORM\Column(length: 16, enumType: MinibusBookingStatus::class)]
    private MinibusBookingStatus $status = MinibusBookingStatus::Pending;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'decided_by_name', length: 200, nullable: true)]
    private ?string $decidedByName = null;

    #[ORM\Column(name: 'decided_at', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Message du gestionnaire à l'emprunteur (motif d'un refus, consigne pour la remise des clés…). */
    #[ORM\Column(name: 'decision_note', type: 'text', nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(name: 'cancelled_at', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** Moment où l'emprunteur a accepté la charte, et version de la charte acceptée (voir MinibusSettings). */
    #[ORM\Column(name: 'charter_accepted_at', nullable: true)]
    private ?\DateTimeImmutable $charterAcceptedAt = null;

    #[ORM\Column(name: 'charter_version', length: 16, nullable: true)]
    private ?string $charterVersion = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, MinibusCheck> */
    #[ORM\OneToMany(targetEntity: MinibusCheck::class, mappedBy: 'booking', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $checks;

    public function __construct(User $requester, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate, string $reason, bool $withTrailer)
    {
        $this->requester = $requester;
        $this->requesterName = $requester->getFullName();
        $this->startDate = $startDate->setTime(0, 0);
        $this->endDate = $endDate->setTime(0, 0);
        $this->reason = trim($reason);
        $this->withTrailer = $withTrailer;
        $this->createdAt = new \DateTimeImmutable();
        $this->checks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getRequester(): ?User { return $this->requester; }
    public function getRequesterName(): string { return $this->requesterName; }
    /** Cette personne est-elle l'emprunteur ? */
    public function isRequestedBy(?User $user): bool
    {
        return $user !== null && $this->requester !== null && $this->requester->getId() === $user->getId();
    }

    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): \DateTimeImmutable { return $this->endDate; }
    public function setDates(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        $this->startDate = $start->setTime(0, 0);
        $this->endDate = $end->setTime(0, 0);
        return $this;
    }

    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self { $this->reason = trim($reason); return $this; }

    public function isWithTrailer(): bool { return $this->withTrailer; }
    public function setWithTrailer(bool $withTrailer): self { $this->withTrailer = $withTrailer; return $this; }

    public function getStatus(): MinibusBookingStatus { return $this->status; }
    public function isPending(): bool { return $this->status === MinibusBookingStatus::Pending; }
    public function isApproved(): bool { return $this->status === MinibusBookingStatus::Approved; }

    public function approve(User $by): self
    {
        $this->status = MinibusBookingStatus::Approved;
        $this->markDecided($by, $this->decisionNote);
        return $this;
    }

    public function refuse(User $by, ?string $note): self
    {
        $this->status = MinibusBookingStatus::Refused;
        $this->markDecided($by, $note);
        return $this;
    }

    public function cancel(): self
    {
        $this->status = MinibusBookingStatus::Cancelled;
        $this->cancelledAt = new \DateTimeImmutable();
        return $this;
    }

    /** Message du gestionnaire (peut accompagner une validation comme un refus). */
    public function setDecisionNote(?string $note): self
    {
        $this->decisionNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        return $this;
    }

    private function markDecided(User $by, ?string $note): void
    {
        $this->decidedBy = $by;
        $this->decidedByName = $by->getFullName();
        $this->decidedAt = new \DateTimeImmutable();
        $this->setDecisionNote($note);
    }

    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function getDecidedByName(): ?string { return $this->decidedByName; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecisionNote(): ?string { return $this->decisionNote; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }

    public function getCharterAcceptedAt(): ?\DateTimeImmutable { return $this->charterAcceptedAt; }
    public function getCharterVersion(): ?string { return $this->charterVersion; }
    public function isCharterAccepted(): bool { return $this->charterAcceptedAt !== null; }
    public function acceptCharter(string $version): self
    {
        $this->charterAcceptedAt = new \DateTimeImmutable();
        $this->charterVersion = $version;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, MinibusCheck> */
    public function getChecks(): Collection { return $this->checks; }

    public function getCheck(MinibusCheckType $type): ?MinibusCheck
    {
        foreach ($this->checks as $check) {
            if ($check->getType() === $type) {
                return $check;
            }
        }

        return null;
    }

    public function addCheck(MinibusCheck $check): self
    {
        if (!$this->checks->contains($check)) {
            $this->checks->add($check);
        }
        return $this;
    }

    /** Les dates de cette demande chevauchent-elles la période (bornes comprises) ? */
    public function overlaps(\DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return $this->startDate <= $end && $start <= $this->endDate;
    }

    /**
     * Étape en cours d'un emprunt validé : charte à accepter → état des lieux de départ → de retour → terminé. null pour
     * une demande non validée.
     */
    public function getStep(): ?string
    {
        if (!$this->isApproved()) {
            return null;
        }
        if (!$this->isCharterAccepted()) {
            return self::STEP_CHARTER;
        }
        if ($this->getCheck(MinibusCheckType::Return) !== null) {
            return self::STEP_DONE;
        }

        return $this->getCheck(MinibusCheckType::Departure) === null ? self::STEP_DEPARTURE : self::STEP_RETURN;
    }

    /** Un état des lieux signale-t-il un problème ? */
    public function hasProblem(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->hasProblem()) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return sprintf('%s · %s → %s', $this->requesterName, $this->startDate->format('d/m/Y'), $this->endDate->format('d/m/Y'));
    }
}
