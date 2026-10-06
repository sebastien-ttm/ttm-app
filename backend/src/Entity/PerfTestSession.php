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
 * Séance de test chronométré (1500 m CAP, 400 m natation, montée 2 km
 * vélo) : les entraîneurs y saisissent le temps de chaque adhérent
 * testé (PerfTestResult).
 */
#[ORM\Entity(repositoryClass: PerfTestSessionRepository::class)]
#[ORM\Table(name: 'perf_test_session')]
#[ORM\Index(name: 'idx_perf_session_test_date', columns: ['test', 'test_date'])]
class PerfTestSession
{
    public const POOL_LENGTHS = [25, 50];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: PerfTest::class)]
    private PerfTest $test = PerfTest::Run1500;

    #[ORM\Column(name: 'test_date', type: 'date_immutable')]
    private \DateTimeImmutable $date;

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

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): self { $this->date = $date; return $this; }

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
        return $this->getTestLabel().' du '.$this->date->format('d/m/Y');
    }
}
