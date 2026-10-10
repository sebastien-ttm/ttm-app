<?php

namespace App\Controller\Api;

use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Enum\PerfTest;
use App\Repository\PerfTestSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tests chronométrés (1500 m, 400 m natation, montée 2 km vélo) côté
 * mobile, onglet Entraînements : pour une année, les temps de TOUS les
 * adhérents classés par séance, avec le meilleur temps du viewer.
 *
 * Réservé aux adhérents licenciés (même règle que l'onglet côté appli).
 * Les temps sont saisis par les entraîneurs (voir PerfTestResultController).
 */
#[IsGranted('ROLE_USER')]
class PerfTestController extends AbstractController
{
    public function __construct(
        private readonly PerfTestSessionRepository $sessions,
    ) {
    }

    /**
     * GET /api/perf-tests?year=2026 — par défaut l'année la plus récente
     * qui a des temps (à défaut, l'année en cours).
     */
    #[Route('/api/perf-tests', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        if (($viewer->getNumLicence() ?? '') === '' || $viewer->isDirigeant()) {
            throw $this->createAccessDeniedException();
        }

        $years = $this->sessions->findYearsWithResults();
        $requested = (int) $request->query->get('year', 0);
        $year = $requested >= 2000 && $requested <= 2100
            ? $requested
            : ($years[0] ?? (int) date('Y'));

        // Une épreuve par groupe ; en natation, un groupe par longueur de
        // bassin (25 m et 50 m ne sont pas comparables).
        /** @var array<string, array{test: PerfTest, pool: ?int, sessions: list<PerfTestSession>}> $groups */
        $groups = [];
        foreach ($this->sessions->findWithResultsForYear($year) as $session) {
            $key = $session->getTest()->value.($session->getPoolLength() !== null ? '_'.$session->getPoolLength() : '');
            $groups[$key] ??= ['test' => $session->getTest(), 'pool' => $session->getPoolLength(), 'sessions' => []];
            $groups[$key]['sessions'][] = $session;
        }

        $order = array_map(static fn (PerfTest $t) => $t->value, PerfTest::cases());
        uasort($groups, static fn (array $a, array $b) => [array_search($a['test']->value, $order, true), $a['pool']]
            <=> [array_search($b['test']->value, $order, true), $b['pool']]);

        $out = [];
        foreach ($groups as $key => $g) {
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
                            $mineBest = ['timeSeconds' => $row['timeSeconds'], 'time' => $row['time'], 'date' => $serialized['date']];
                        }
                    }
                }
            }
            $label = $g['test']->label().($g['pool'] !== null ? ' — bassin '.$g['pool'].' m' : '');
            $out[] = [
                'key' => $key,
                'test' => $g['test']->value,
                'label' => $label,
                'icon' => $g['test']->icon(),
                'poolLength' => $g['pool'],
                'mine' => $mineCount === 0 ? null : ['count' => $mineCount, 'best' => $mineBest],
                'sessions' => $sessions,
            ];
        }

        return new JsonResponse([
            'year' => $year,
            // L'année demandée figure toujours dans le sélecteur, même vide.
            'years' => in_array($year, $years, true) ? $years : array_values(array_unique([$year, ...$years])),
            'groups' => $out,
        ]);
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
            ?: strcmp($a->getUser()->getNom(), $b->getUser()->getNom()));

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
                'userId' => $r->getUser()->getId(),
                'fullName' => $r->getUser()->getFullName(),
                'timeSeconds' => $r->getTimeSeconds(),
                'time' => PerfTestResult::format($r->getTimeSeconds()),
                'mine' => $r->getUser()->getId() === $viewer->getId(),
            ];
        }

        return [
            'id' => $session->getId(),
            'date' => $session->getDate()->format('Y-m-d'),
            'notes' => $session->getNotes(),
            'participants' => count($rows),
            'results' => $rows,
        ];
    }
}
