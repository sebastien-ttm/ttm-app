<?php

namespace App\Service\PerfTest;

use App\Entity\PerfTestPace;
use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Enum\PerfTest;
use App\Repository\PerfTestPaceRepository;
use App\Repository\PerfTestResultRepository;
use App\Repository\PerfTestSessionRepository;

/**
 * Allures du club (1 à 5) : le temps réalisé à un test classe l'adhérent dans une allure, par épreuve. Les intervalles
 * sont ceux de PerfTestPace, modifiables par les entraîneurs depuis le backend.
 *
 * Natation : un temps réalisé en bassin de 50 m est diminué de 10 secondes pour la comparaison (classement dans une
 * allure, meilleur temps de la saison) ; l'appli et le backend le mentionnent, avec le temps réel.
 *
 * Récap d'une saison : un adhérent a UN temps par épreuve — son meilleur de la saison (temps retenu, après la
 * correction du bassin de 50 m) — avec la séance où il a été réalisé.
 */
class PerfTestAllureService
{
    /** Secondes retirées d'un temps de natation en bassin de 50 m pour le comparer aux autres. */
    public const SWIM_50M_DEDUCTION_SECONDS = 10;

    /** Ordre d'affichage des épreuves : course, natation, vélo. */
    public const TESTS = [PerfTest::Run1500, PerfTest::Swim400, PerfTest::BikeClimb2k];

    /** @var array<string, PerfTestPace>|null */
    private ?array $scales = null;

    public function __construct(
        private readonly PerfTestPaceRepository $paces,
        private readonly PerfTestResultRepository $results,
    ) {
    }

    /** Échelle d'allures d'une épreuve : celle enregistrée, à défaut les valeurs d'origine. */
    public function scale(PerfTest $test): PerfTestPace
    {
        if ($this->scales === null) {
            try {
                $this->scales = $this->paces->findAllByTest();
            } catch (\Throwable) {
                // Table pas encore créée (migration en attente) : on retombe sur les valeurs d'origine.
                $this->scales = [];
            }
        }

        return $this->scales[$test->value] ??= new PerfTestPace($test);
    }

    /** Le temps d'une natation en bassin de 50 m est corrigé avant comparaison. */
    public function isAdjusted(PerfTest $test, ?int $poolLength): bool
    {
        return $test === PerfTest::Swim400 && $poolLength === 50;
    }

    /** Temps retenu pour la comparaison (secondes) : le temps réel, moins 10 s pour une natation en bassin de 50 m. */
    public function effectiveSeconds(PerfTest $test, ?int $poolLength, int $seconds): int
    {
        return $this->isAdjusted($test, $poolLength) ? max(1, $seconds - self::SWIM_50M_DEDUCTION_SECONDS) : $seconds;
    }

    /** Allure (1 à 5) d'un temps retenu. */
    public function levelFor(PerfTest $test, int $effectiveSeconds): int
    {
        return $this->scale($test)->levelFor($effectiveSeconds);
    }

    /**
     * Les intervalles d'une épreuve, pour l'affichage.
     *
     * @return list<array{level: int, label: string}>
     */
    public function intervals(PerfTest $test): array
    {
        return array_map(
            static fn (array $i) => ['level' => $i['level'], 'label' => $i['label']],
            $this->scale($test)->intervals(),
        );
    }

    /** Mention affichée pour la natation (null pour les autres épreuves). */
    public function adjustmentNote(PerfTest $test): ?string
    {
        return $test === PerfTest::Swim400
            ? sprintf('Un temps réalisé en bassin de 50 m est diminué de %d secondes pour le classement dans une allure.', self::SWIM_50M_DEDUCTION_SECONDS)
            : null;
    }

    /**
     * Récap d'une épreuve sur une saison : un temps par adhérent (son meilleur, après correction du bassin), son allure
     * et la séance où il a été réalisé, du plus rapide au plus lent. `$viewer` : repère mes lignes.
     *
     * @return array<string, mixed>
     */
    public function seasonRecap(PerfTest $test, int $startYear, ?User $viewer): array
    {
        /** @var array<string, array{result: PerfTestResult, effective: int}> $best */
        $best = [];
        foreach ($this->results->findForSeasonAndTest($test, $startYear) as $result) {
            $session = $result->getSession();
            $effective = $this->effectiveSeconds($test, $session->getPoolLength(), $result->getTimeSeconds());
            $key = $result->getUser() !== null ? 'u'.$result->getUser()->getId() : 'n'.mb_strtolower((string) $result->getLegacyName());
            // Meilleur temps retenu ; à égalité, la séance la plus récente.
            if (!isset($best[$key])
                || $effective < $best[$key]['effective']
                || ($effective === $best[$key]['effective'] && $session->getDate() > $best[$key]['result']->getSession()->getDate())) {
                $best[$key] = ['result' => $result, 'effective' => $effective];
            }
        }

        $entries = array_values($best);
        usort($entries, static fn (array $a, array $b) => [$a['effective'], $a['result']->getSortName()] <=> [$b['effective'], $b['result']->getSortName()]);

        $rows = [];
        $mine = null;
        foreach ($entries as $entry) {
            $row = $this->row($test, $entry['result'], $entry['effective'], $viewer);
            $rows[] = $row;
            if ($row['mine']) {
                $mine = ['level' => $row['level'], 'time' => $row['time'], 'effectiveTime' => $row['effectiveTime'], 'adjusted' => $row['adjusted']];
            }
        }

        return [
            'test' => $test->value,
            'label' => $test->label(),
            'shortLabel' => $test->shortLabel(),
            'icon' => $test->icon(),
            'intervals' => $this->intervals($test),
            'adjustmentNote' => $this->adjustmentNote($test),
            'rows' => $rows,
            'mine' => $mine,
        ];
    }

    /**
     * Mes allures : pour chaque épreuve où j'ai un temps, mon meilleur temps (retenu) de la saison la plus récente où je
     * l'ai passée, avec mon allure et la séance. Dans l'ordre course, natation, vélo ; vide si je n'ai aucun temps.
     *
     * @return list<array<string, mixed>>
     */
    public function myAllures(User $user): array
    {
        /** @var array<string, array{season: int, result: PerfTestResult, effective: int}> $latest */
        $latest = [];
        foreach ($this->results->findByUserWithSession($user) as $result) {
            $session = $result->getSession();
            $test = $session->getTest();
            $season = PerfTestSessionRepository::seasonStartYear($session->getDate());
            $effective = $this->effectiveSeconds($test, $session->getPoolLength(), $result->getTimeSeconds());
            $current = $latest[$test->value] ?? null;
            if ($current === null
                || $season > $current['season']
                || ($season === $current['season'] && ($effective < $current['effective']
                    || ($effective === $current['effective'] && $session->getDate() > $current['result']->getSession()->getDate())))) {
                $latest[$test->value] = ['season' => $season, 'result' => $result, 'effective' => $effective];
            }
        }

        $out = [];
        foreach (self::TESTS as $test) {
            if (!isset($latest[$test->value])) {
                continue;
            }
            $entry = $latest[$test->value];
            $row = $this->row($test, $entry['result'], $entry['effective'], $user);
            $out[] = [
                'test' => $test->value,
                'label' => $test->label(),
                'shortLabel' => $test->shortLabel(),
                'icon' => $test->icon(),
                'level' => $row['level'],
                'season' => $entry['season'],
                'seasonLabel' => PerfTestSessionRepository::seasonLabel($entry['season']),
                'time' => $row['time'],
                'effectiveTime' => $row['effectiveTime'],
                'adjusted' => $row['adjusted'],
                'poolLength' => $row['poolLength'],
                'session' => $row['session'],
                'intervals' => $this->intervals($test),
                'adjustmentNote' => $this->adjustmentNote($test),
            ];
        }

        return $out;
    }

    /**
     * Une ligne de récap : l'adhérent, son temps (réel et retenu), son allure et la séance.
     *
     * @return array<string, mixed>
     */
    private function row(PerfTest $test, PerfTestResult $result, int $effective, ?User $viewer): array
    {
        $session = $result->getSession();

        return [
            'userId' => $result->getUser()?->getId(),
            'fullName' => $result->getDisplayName(),
            'level' => $this->levelFor($test, $effective),
            'timeSeconds' => $result->getTimeSeconds(),
            'time' => PerfTestResult::format($result->getTimeSeconds()),
            'effectiveSeconds' => $effective,
            'effectiveTime' => PerfTestResult::format($effective),
            // Natation en bassin de 50 m : le temps retenu pour l'allure diffère du temps réel (à mentionner).
            'adjusted' => $this->isAdjusted($test, $session->getPoolLength()),
            'poolLength' => $session->getPoolLength(),
            'session' => $this->sessionItem($session),
            'mine' => $viewer !== null && $result->getUser() !== null && $result->getUser()->getId() === $viewer->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionItem(PerfTestSession $session): array
    {
        return [
            'id' => $session->getId(),
            // Période de la prise de temps ; endDate null = une seule journée.
            'date' => $session->getDate()->format('Y-m-d'),
            'endDate' => $session->isSingleDay() ? null : $session->getPeriodEnd()->format('Y-m-d'),
            'notes' => $session->getNotes(),
        ];
    }
}
