<?php

namespace App\Entity;

use App\Enum\PerfTest;
use App\Repository\PerfTestPaceRepository;
use App\Repository\PerfTestSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Échelle des 5 allures du club pour une épreuve (1500 m ou 2000 m course, 400 m natation, montée 2 km vélo) ET une
 * saison : le temps réalisé au test classe l'adhérent dans une allure de 1 (la plus rapide) à 5. Une ligne par épreuve et
 * par saison, modifiable par les entraîneurs depuis le backend : le niveau général du club change d'une année à l'autre,
 * et les intervalles se règlent pour équilibrer le nombre d'adhérents dans chaque allure.
 *
 * Chaque allure est définie par son temps MAXIMAL inclus, en secondes : l'allure 1 va jusqu'à `level1Max`, l'allure 2
 * jusqu'à `level2Max`, etc. ; l'allure 5 n'a pas de borne (tout temps plus lent que `level4Max`).
 *
 * Une saison sans ligne reprend celle de la saison précédente la plus proche (voir PerfTestAllureService::scale).
 * `seasonStartYear` : année de début de la saison (2025 = saison 2025-2026, du 1er sept. au 31 août).
 */
#[ORM\Entity(repositoryClass: PerfTestPaceRepository::class)]
#[ORM\Table(name: 'perf_test_pace')]
#[ORM\UniqueConstraint(name: 'uniq_perf_test_pace_test_season', columns: ['test', 'season_start_year'])]
#[UniqueEntity(fields: ['test', 'seasonStartYear'], message: 'Cette épreuve a déjà des intervalles pour cette saison : modifiez la ligne existante.', errorPath: 'seasonStartYear')]
class PerfTestPace
{
    /** Nombre d'allures du club. */
    public const LEVELS = 5;

    /**
     * Intervalles d'origine : temps max inclus des allures 1 à 4, en secondes. Les trois premiers viennent du fichier
     * « Allures - Intervalles » du club ; le 2000 m reprend ceux du 1500 m au prorata de la distance (× 4/3), à ajuster.
     * Sert de repli quand aucune ligne n'existe pour l'épreuve.
     *
     * @var array<string, array{int, int, int, int}>
     */
    public const DEFAULTS = [
        'swim_400' => [359, 389, 419, 479],      // < 6'00 · 6'00-6'29 · 6'30-6'59 · 7'00-7'59 · 8'00 et plus
        'bike_climb_2k' => [334, 364, 402, 449], // < 5'35 · 5'35-6'04 · 6'05-6'42 · 6'43-7'29 · 7'30 et plus
        'run_1500' => [299, 325, 345, 390],      // < 5'00 · 5'00-5'25 · 5'26-5'45 · 5'46-6'30 · 6'31 et plus
        'run_2000' => [399, 433, 460, 520],      // < 6'40 · 6'40-7'13 · 7'14-7'40 · 7'41-8'40 · 8'41 et plus
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, enumType: PerfTest::class)]
    private PerfTest $test;

    /** Année de début de la saison (2025 = saison 2025-2026). */
    #[ORM\Column(type: 'smallint')]
    private int $seasonStartYear;

    #[ORM\Column(type: 'smallint')]
    private int $level1Max;

    #[ORM\Column(type: 'smallint')]
    private int $level2Max;

    #[ORM\Column(type: 'smallint')]
    private int $level3Max;

    #[ORM\Column(type: 'smallint')]
    private int $level4Max;

    public function __construct(PerfTest $test, int $seasonStartYear)
    {
        $this->test = $test;
        $this->seasonStartYear = $seasonStartYear;
        [$this->level1Max, $this->level2Max, $this->level3Max, $this->level4Max] = self::DEFAULTS[$test->value];
    }

    public function __toString(): string
    {
        return $this->test->label().' — '.$this->getSeasonLabel();
    }

    public function getId(): ?int { return $this->id; }
    public function getTest(): PerfTest { return $this->test; }
    public function setTest(PerfTest $test): self { $this->test = $test; return $this; }
    public function getTestLabel(): string { return $this->test->icon().' '.$this->test->label(); }

    public function getSeasonStartYear(): int { return $this->seasonStartYear; }
    public function setSeasonStartYear(int $seasonStartYear): self { $this->seasonStartYear = $seasonStartYear; return $this; }
    /** « 2025-2026 ». */
    public function getSeasonLabel(): string { return PerfTestSessionRepository::seasonLabel($this->seasonStartYear); }

    /**
     * Saisons proposées dans le backend, de la prochaine (la saison démarre le 1er septembre) à 2015 : « 2026-2027 » => 2026.
     *
     * @return array<string, int>
     */
    public static function seasonChoices(): array
    {
        $out = [];
        for ($year = PerfTestSessionRepository::seasonStartYear(new \DateTimeImmutable('today')) + 1; $year >= 2015; --$year) {
            $out[PerfTestSessionRepository::seasonLabel($year)] = $year;
        }

        return $out;
    }

    /** Reprend les 4 temps maximaux d'une autre échelle (copie d'une saison à l'autre). */
    public function copyThresholds(self $other): self
    {
        [$this->level1Max, $this->level2Max, $this->level3Max, $this->level4Max] = $other->getThresholds();

        return $this;
    }

    /**
     * Temps max inclus des allures 1 à 4, en secondes.
     *
     * @return array{int, int, int, int}
     */
    public function getThresholds(): array
    {
        return [$this->level1Max, $this->level2Max, $this->level3Max, $this->level4Max];
    }

    /** Allure (1 à 5) d'un temps, en secondes : la première dont le temps max n'est pas dépassé. */
    public function levelFor(int $seconds): int
    {
        foreach ($this->getThresholds() as $i => $max) {
            if ($seconds <= $max) {
                return $i + 1;
            }
        }

        return self::LEVELS;
    }

    /**
     * Les 5 intervalles, pour l'affichage : « Moins de 6:00 », « De 6:00 à 6:29 »… « 8:00 et plus ».
     *
     * @return list<array{level: int, label: string, maxSeconds: ?int}>
     */
    public function intervals(): array
    {
        $max = $this->getThresholds();
        $out = [['level' => 1, 'label' => 'Moins de '.self::fmt($max[0] + 1), 'maxSeconds' => $max[0]]];
        for ($i = 1; $i < 4; ++$i) {
            $out[] = ['level' => $i + 1, 'label' => 'De '.self::fmt($max[$i - 1] + 1).' à '.self::fmt($max[$i]), 'maxSeconds' => $max[$i]];
        }
        $out[] = ['level' => 5, 'label' => self::fmt($max[3] + 1).' et plus', 'maxSeconds' => null];

        return $out;
    }

    /** « 1 : Moins de 6:00 · 2 : De 6:00 à 6:29 · … » : résumé d'une ligne pour la liste du backend. */
    public function getIntervalsText(): string
    {
        return implode(' · ', array_map(static fn (array $i) => $i['level'].' : '.$i['label'], $this->intervals()));
    }

    /** « 5:59 » : minutes et secondes, comme les temps affichés partout (les épreuves durent moins d'une heure). */
    public static function fmt(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    // ---- Saisie dans le backend : « m:ss » (ex : 5:59 ou 5'59), converti en secondes ----

    public function getLevel1MaxLabel(): string { return self::fmt($this->level1Max); }
    public function setLevel1MaxLabel(?string $label): self { $this->level1Max = self::seconds($label) ?? $this->level1Max; return $this; }

    public function getLevel2MaxLabel(): string { return self::fmt($this->level2Max); }
    public function setLevel2MaxLabel(?string $label): self { $this->level2Max = self::seconds($label) ?? $this->level2Max; return $this; }

    public function getLevel3MaxLabel(): string { return self::fmt($this->level3Max); }
    public function setLevel3MaxLabel(?string $label): self { $this->level3Max = self::seconds($label) ?? $this->level3Max; return $this; }

    public function getLevel4MaxLabel(): string { return self::fmt($this->level4Max); }
    public function setLevel4MaxLabel(?string $label): self { $this->level4Max = self::seconds($label) ?? $this->level4Max; return $this; }

    /** Temps saisi (« 5'59 », « 5:59 », « 5.59 »…) → secondes ; null si illisible ou vide. */
    private static function seconds(?string $label): ?int
    {
        return $label === null || trim($label) === '' ? null : PerfTestResult::parse($label);
    }

    /** Les temps max doivent croître d'une allure à l'autre (sinon une allure serait vide). */
    #[Assert\Callback]
    public function validateOrder(ExecutionContextInterface $context): void
    {
        $max = $this->getThresholds();
        for ($i = 1; $i < 4; ++$i) {
            if ($max[$i] <= $max[$i - 1]) {
                $context->buildViolation('Les temps doivent augmenter d\'une allure à l\'autre : l\'allure '.($i + 1).' doit aller au-delà de l\'allure '.$i.'.')
                    ->atPath('level'.($i + 1).'MaxLabel')->addViolation();

                return;
            }
        }
    }
}
