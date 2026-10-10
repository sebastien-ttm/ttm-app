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
    public const MAX_EXTRA_DATES = 10;
    /** Notes des séances créées automatiquement pour les temps déclarés par les adhérents (une par épreuve et par jour). */
    public const INDIVIDUAL_NOTES = 'Temps individuels (déclarés par les adhérents)';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: PerfTest::class)]
    private PerfTest $test = PerfTest::Run1500;

    #[ORM\Column(name: 'test_date', type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /**
     * Autres dates de la même séance quand elle s'étale sur plusieurs jours
     * (ex : un 2e soir) — « Y-m-d », la date principale étant `date`.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'extra_dates', type: 'json', nullable: true)]
    private ?array $extraDates = null;

    /**
     * Saisies illisibles du champ « autres dates » (formulaire admin) :
     * non persistées, signalées à la validation.
     *
     * @var list<string>
     */
    private array $invalidExtraDates = [];

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
    public function validateExtraDates(ExecutionContextInterface $context): void
    {
        if ($this->invalidExtraDates !== []) {
            $context->buildViolation('Date illisible : « {{ value }} ». Écrivez les dates au format JJ/MM/AAAA, séparées par des virgules.')
                ->setParameter('{{ value }}', implode(', ', $this->invalidExtraDates))
                ->atPath('extraDatesText')->addViolation();
        }
        if (count($this->extraDates ?? []) > self::MAX_EXTRA_DATES) {
            $context->buildViolation('Maximum {{ limit }} dates supplémentaires.')
                ->setParameter('{{ limit }}', (string) self::MAX_EXTRA_DATES)
                ->atPath('extraDatesText')->addViolation();
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

    /**
     * Toutes les dates de la séance (principale + autres), sans doublon,
     * de la plus ancienne à la plus récente.
     *
     * @return list<\DateTimeImmutable>
     */
    public function getDates(): array
    {
        $byKey = [$this->date->format('Y-m-d') => $this->date];
        foreach ($this->extraDates ?? [] as $iso) {
            $d = self::parseDate((string) $iso);
            if ($d !== null) {
                $byKey[$d->format('Y-m-d')] ??= $d;
            }
        }
        ksort($byKey);
        return array_values($byKey);
    }

    /**
     * Libellé des dates : « 12/03/2026 », « 12/03 et 14/03/2026 »,
     * « 12/03, 14/03 et 19/03/2026 » (année donnée une fois si commune).
     */
    public function getDatesLabel(): string
    {
        $dates = $this->getDates();
        if (count($dates) === 1) {
            return $dates[0]->format('d/m/Y');
        }
        $years = array_unique(array_map(static fn (\DateTimeImmutable $d) => $d->format('Y'), $dates));
        $sameYear = count($years) === 1;
        $parts = array_map(static fn (\DateTimeImmutable $d) => $d->format($sameYear ? 'd/m' : 'd/m/Y'), $dates);
        $last = array_pop($parts);
        return implode(', ', $parts).' et '.$last.($sameYear ? '/'.reset($years) : '');
    }

    /** Champ de formulaire : les dates AUTRES que la principale, « 14/03/2026, 21/03/2026 ». */
    public function getExtraDatesText(): string
    {
        $main = $this->date->format('Y-m-d');
        $others = array_filter($this->getDates(), static fn (\DateTimeImmutable $d) => $d->format('Y-m-d') !== $main);
        return implode(', ', array_map(static fn (\DateTimeImmutable $d) => $d->format('d/m/Y'), $others));
    }

    /**
     * Saisie libre de dates (JJ/MM/AAAA ou AAAA-MM-JJ) séparées par des
     * virgules, points-virgules ou espaces. Les saisies illisibles sont
     * mémorisées pour la validation (validateExtraDates).
     */
    public function setExtraDatesText(?string $text): self
    {
        $this->invalidExtraDates = [];
        $keys = [];
        foreach (preg_split('/[\s,;]+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $d = self::parseDate($token);
            if ($d === null) {
                $this->invalidExtraDates[] = $token;
                continue;
            }
            $keys[$d->format('Y-m-d')] = true;
        }
        ksort($keys);
        $this->extraDates = $keys === [] ? null : array_keys($keys);
        return $this;
    }

    private static function parseDate(string $raw): ?\DateTimeImmutable
    {
        if (preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$~', $raw, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $raw, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($year < 2000 || $year > 2100 || !checkdate($month, $day, $year)) {
            return null;
        }
        return (new \DateTimeImmutable('today'))->setDate($year, $month, $day);
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
        return $this->getTestLabel().(count($this->getDates()) > 1 ? ' des ' : ' du ').$this->getDatesLabel();
    }
}
