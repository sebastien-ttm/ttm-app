<?php

namespace App\Controller\Api;

use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Enum\PerfTest;
use App\Repository\PerfTestResultRepository;
use App\Repository\PerfTestSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tests chronométrés (1500 m, 400 m natation, montée 2 km vélo) côté
 * mobile, onglet Entraînements : pour une saison d'entraînement, les temps
 * de TOUS les adhérents classés par séance, avec le meilleur temps du viewer.
 *
 * Réservé aux adhérents licenciés (même règle que l'onglet côté appli).
 * Les temps sont saisis par les entraîneurs (voir PerfTestResultController).
 */
#[IsGranted('ROLE_USER')]
class PerfTestController extends AbstractController
{
    public function __construct(
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestResultRepository $results,
    ) {
    }

    /**
     * GET /api/perf-tests/mine — « Mon évolution » : tous MES temps, toutes
     * saisons, par épreuve (et bassin en natation), du plus ancien au plus
     * récent, avec l'écart avec le test précédent, le rang dans la séance
     * et mon record.
     */
    #[Route('/api/perf-tests/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessLicensed($viewer);

        $mine = $this->results->findByUserWithSession($viewer);
        $times = $this->results->findTimesBySessionIds(
            array_values(array_unique(array_map(static fn (PerfTestResult $r) => (int) $r->getSession()->getId(), $mine))),
        );

        /** @var array<string, array{test: PerfTest, pool: ?int, results: list<PerfTestResult>}> $groups */
        $groups = [];
        foreach ($mine as $r) {
            $session = $r->getSession();
            $key = self::groupKey($session);
            $groups[$key] ??= ['test' => $session->getTest(), 'pool' => $session->getPoolLength(), 'results' => []];
            $groups[$key]['results'][] = $r;
        }

        $out = [];
        foreach ($this->sortGroups($groups) as $key => $g) {
            $best = null;
            foreach ($g['results'] as $r) {
                if ($best === null || $r->getTimeSeconds() < $best->getTimeSeconds()) {
                    $best = $r;
                }
            }

            $rows = [];
            $previous = null;
            foreach ($g['results'] as $r) {
                $session = $r->getSession();
                $all = $times[(int) $session->getId()] ?? [];
                $t = $r->getTimeSeconds();
                $dates = array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $session->getDates());
                $rows[] = [
                    'sessionId' => $session->getId(),
                    'dates' => $dates,
                    'season' => PerfTestSessionRepository::seasonStartYear($session->getDate()),
                    'seasonLabel' => PerfTestSessionRepository::seasonLabel(PerfTestSessionRepository::seasonStartYear($session->getDate())),
                    'timeSeconds' => $t,
                    'time' => PerfTestResult::format($t),
                    'rank' => 1 + count(array_filter($all, static fn (int $other) => $other < $t)),
                    'participants' => count($all),
                    // Écart avec mon test précédent sur la même épreuve (négatif = plus rapide).
                    'deltaSeconds' => $previous === null ? null : $t - $previous,
                    'isBest' => $r === $best,
                ];
                $previous = $t;
            }

            $bestSession = $best->getSession();
            $out[] = [
                'key' => $key,
                'test' => $g['test']->value,
                'label' => $g['test']->label().($g['pool'] !== null ? ' — bassin '.$g['pool'].' m' : ''),
                'shortLabel' => $g['test']->shortLabel().($g['pool'] !== null ? ' · '.$g['pool'].' m' : ''),
                'icon' => $g['test']->icon(),
                'poolLength' => $g['pool'],
                'best' => [
                    'timeSeconds' => $best->getTimeSeconds(),
                    'time' => PerfTestResult::format($best->getTimeSeconds()),
                    'seasonLabel' => PerfTestSessionRepository::seasonLabel(PerfTestSessionRepository::seasonStartYear($bestSession->getDate())),
                    'dates' => array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $bestSession->getDates()),
                ],
                'results' => $rows,
            ];
        }

        return new JsonResponse(['groups' => $out]);
    }

    /**
     * GET /api/perf-tests?season=2025 — saison d'entraînement identifiée par
     * son année de début (2025 = saison 2025-2026, du 1er sept. au 31 août).
     * Par défaut la saison la plus récente qui a des temps (à défaut, la
     * saison en cours).
     */
    #[Route('/api/perf-tests', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessLicensed($viewer);

        $seasons = $this->sessions->findSeasonsWithResults();
        $requested = (int) $request->query->get('season', 0);
        $season = $requested >= 2000 && $requested <= 2100
            ? $requested
            : ($seasons[0] ?? PerfTestSessionRepository::seasonStartYear(new \DateTimeImmutable('today')));

        // Une épreuve par groupe ; en natation, un groupe par longueur de
        // bassin (25 m et 50 m ne sont pas comparables).
        /** @var array<string, array{test: PerfTest, pool: ?int, sessions: list<PerfTestSession>}> $groups */
        $groups = [];
        foreach ($this->sessions->findWithResultsForSeason($season) as $session) {
            $key = self::groupKey($session);
            $groups[$key] ??= ['test' => $session->getTest(), 'pool' => $session->getPoolLength(), 'sessions' => []];
            $groups[$key]['sessions'][] = $session;
        }

        $out = [];
        foreach ($this->sortGroups($groups) as $key => $g) {
            $mineBest = null;
            $mineCount = 0;
            $sessions = [];
            foreach ($g['sessions'] as $session) {
                $serialized = $this->serializeSession($session, $viewer);
                $sessions[] = $serialized;
                foreach ($serialized['results'] as $row) {
                    if ($row['mine']) {
                        $mineCount++;
                        if ($mineBest === null || $row['timeSeconds'] < $mineBest['timeSeconds']) {
                            $mineBest = ['timeSeconds' => $row['timeSeconds'], 'time' => $row['time'], 'dates' => $serialized['dates']];
                        }
                    }
                }
            }
            $label = $g['test']->label().($g['pool'] !== null ? ' — bassin '.$g['pool'].' m' : '');
            $out[] = [
                'key' => $key,
                'test' => $g['test']->value,
                'label' => $label,
                'shortLabel' => $g['test']->shortLabel().($g['pool'] !== null ? ' · '.$g['pool'].' m' : ''),
                'icon' => $g['test']->icon(),
                'poolLength' => $g['pool'],
                'mine' => $mineCount === 0 ? null : ['count' => $mineCount, 'best' => $mineBest],
                'sessions' => $sessions,
            ];
        }

        // La saison affichée figure toujours dans le sélecteur, même sans temps.
        if (!in_array($season, $seasons, true)) {
            $seasons[] = $season;
            rsort($seasons);
        }

        return new JsonResponse([
            'season' => $season,
            'seasons' => array_map(
                static fn (int $y) => ['year' => $y, 'label' => PerfTestSessionRepository::seasonLabel($y)],
                $seasons,
            ),
            'groups' => $out,
        ]);
    }

    /** Réservé aux adhérents licenciés (même règle que l'onglet Entraînements de l'appli). */
    private function denyUnlessLicensed(User $viewer): void
    {
        if (($viewer->getNumLicence() ?? '') === '' || $viewer->isDirigeant()) {
            throw $this->createAccessDeniedException();
        }
    }

    /** Une épreuve par groupe ; en natation, un groupe par longueur de bassin. */
    private static function groupKey(PerfTestSession $session): string
    {
        return $session->getTest()->value.($session->getPoolLength() !== null ? '_'.$session->getPoolLength() : '');
    }

    /**
     * Ordre des épreuves de l'enum, puis longueur de bassin croissante.
     *
     * @template T of array{test: PerfTest, pool: ?int}
     * @param array<string, T> $groups
     * @return array<string, T>
     */
    private function sortGroups(array $groups): array
    {
        $order = array_map(static fn (PerfTest $t) => $t->value, PerfTest::cases());
        uasort($groups, static fn (array $a, array $b) => [array_search($a['test']->value, $order, true), $a['pool']]
            <=> [array_search($b['test']->value, $order, true), $b['pool']]);
        return $groups;
    }

    /**
     * Une séance : temps classés du plus rapide au plus lent (ex æquo =
     * même rang).
     *
     * @return array<string, mixed>
     */
    private function serializeSession(PerfTestSession $session, User $viewer): array
    {
        $results = $session->getResults()->toArray();
        usort($results, static fn (PerfTestResult $a, PerfTestResult $b) => $a->getTimeSeconds() <=> $b->getTimeSeconds()
            ?: strcmp($a->getSortName(), $b->getSortName()));

        $rows = [];
        $rank = 0;
        $previous = null;
        foreach ($results as $i => $r) {
            if ($r->getTimeSeconds() !== $previous) {
                $rank = $i + 1;
                $previous = $r->getTimeSeconds();
            }
            $rows[] = [
                'rank' => $rank,
                // null pour un ancien adhérent sans compte (nom conservé tel quel).
                'userId' => $r->getUser()?->getId(),
                'fullName' => $r->getDisplayName(),
                'timeSeconds' => $r->getTimeSeconds(),
                'time' => PerfTestResult::format($r->getTimeSeconds()),
                'mine' => $r->getUser() !== null && $r->getUser()->getId() === $viewer->getId(),
            ];
        }

        return [
            'id' => $session->getId(),
            // Première date (tri) + toutes les dates de la séance (ex : 2 soirs).
            'date' => $session->getDate()->format('Y-m-d'),
            'dates' => array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $session->getDates()),
            'notes' => $session->getNotes(),
            'participants' => count($rows),
            'results' => $rows,
        ];
    }
}
