<?php

namespace App\Entity;

use App\Enum\PerfTest;
use App\Repository\PerfTestSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Prise de temps (test chronométré : 1500 m CAP, 400 m natation, montée 2 km
 * vélo) sur une PÉRIODE — un seul jour ou plusieurs (ex : 2 soirs) : les
 * entraîneurs y saisissent le temps de chaque adhérent testé
 * (PerfTestResult), et y rattachent les temps déclarés par les adhérents.
 */
#[ORM\Entity(repositoryClass: PerfTestSessionRepository::class)]
#[ORM\Table(name: 'perf_test_session')]
#[ORM\Index(name: 'idx_perf_session_test_date', columns: ['test', 'test_date'])]
class PerfTestSession
{
    public const POOL_LENGTHS = [25, 50];
    /** Durée maximale d'une période (jours) : au-delà, c'est presque sûrement une faute de frappe. */
    public const MAX_PERIOD_DAYS = 366;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: PerfTest::class)]
    private PerfTest $test = PerfTest::Run1500;

    /** Début de la période (classe la séance dans une saison, sert au tri). */
    #[ORM\Column(name: 'test_date', type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /** Fin de la période ; null = une seule journée (le jour de `date`). */
    #[ORM\Column(name: 'end_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    /** Longueur du bassin en mètres (natation uniquement). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $poolLength = null;

    /** Lieu, groupe testé, conditions… */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PerfTestResult> */
    #[ORM\OneToMany(targetEntity: PerfTestResult::class, mappedBy: 'session')]
    private Collection $results;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->results = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validatePoolLength(ExecutionContextInterface $context): void
    {
        if ($this->test->needsPoolLength() && !in_array($this->poolLength, self::POOL_LENGTHS, true)) {
            $context->buildViolation('Choisissez la longueur du bassin (25 ou 50 m).')
                ->atPath('poolLength')->addViolation();
        }
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ($this->endDate === null) {
            return;
        }
        if ($this->endDate < $this->date) {
            $context->buildViolation('La fin de la période ne peut pas précéder son début.')
                ->atPath('endDate')->addViolation();
        } elseif ($this->date->diff($this->endDate)->days > self::MAX_PERIOD_DAYS) {
            $context->buildViolation('Période trop longue ({{ limit }} jours maximum).')
                ->setParameter('{{ limit }}', (string) self::MAX_PERIOD_DAYS)
                ->atPath('endDate')->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getTest(): PerfTest { return $this->test; }
    public function setTest(PerfTest $test): self
    {
        $this->test = $test;
        if (!$test->needsPoolLength()) {
            $this->poolLength = null;
        }
        return $this;
    }

    /** Début de la période. */
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): self { $this->date = $date; return $this; }

    /** Fin de la période telle que saisie (null = une seule journée) — champ du formulaire. */
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        // Même jour que le début = une seule journée.
        $this->endDate = $endDate !== null && $endDate->format('Y-m-d') === $this->date->format('Y-m-d') ? null : $endDate;
        return $this;
    }

    /** Dernier jour de la période (le début pour une prise de temps d'une seule journée). */
    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->endDate !== null && $this->endDate > $this->date ? $this->endDate : $this->date;
    }

    public function isSingleDay(): bool
    {
        return $this->getPeriodEnd()->format('Y-m-d') === $this->date->format('Y-m-d');
    }

    /**
     * Libellé de la période : « 12/03/2026 » pour un jour, « du 12/03 au
     * 14/03/2026 » sur plusieurs (année donnée une fois si commune).
     */
    public function getDatesLabel(): string
    {
        if ($this->isSingleDay()) {
            return $this->date->format('d/m/Y');
        }
        $end = $this->getPeriodEnd();
        $sameYear = $this->date->format('Y') === $end->format('Y');
        return 'du '.$this->date->format($sameYear ? 'd/m' : 'd/m/Y').' au '.$end->format('d/m/Y');
    }

    public function getPoolLength(): ?int { return $this->poolLength; }
    public function setPoolLength(?int $poolLength): self
    {
        $this->poolLength = $this->test->needsPoolLength() ? $poolLength : null;
        return $this;
    }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self
    {
        $notes = $notes !== null ? trim($notes) : null;
        $this->notes = $notes !== '' ? $notes : null;
        return $this;
    }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, PerfTestResult> */
    public function getResults(): Collection { return $this->results; }
    public function getResultsCount(): int { return $this->results->count(); }

    /** Libellé complet de l'épreuve, bassin compris (« 400 m natation — bassin 25 m »). */
    public function getTestLabel(): string
    {
        return $this->test->label().($this->poolLength !== null ? ' — bassin '.$this->poolLength.' m' : '');
    }

    public function __toString(): string
    {
        return $this->getTestLabel().' — '.$this->getDatesLabel();
    }
}
