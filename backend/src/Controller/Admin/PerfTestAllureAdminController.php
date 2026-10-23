<?php

namespace App\Controller\Admin;

use App\Repository\PerfTestSessionRepository;
use App\Service\PerfTest\PerfTestAllureService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Récap général des allures : pour une saison (sept. → août) et chacune des trois épreuves, les adhérents classés dans
 * une allure de 1 à 5 d'après leur meilleur temps de la saison, avec la séance où il a été réalisé. Natation : un temps
 * en bassin de 50 m est diminué de 10 secondes pour le classement (le temps réel et la mention sont affichés).
 * Les intervalles se modifient dans « Intervalles d'allure ».
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class PerfTestAllureAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    public function __construct(
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestAllureService $allures,
    ) {
    }

    #[Route('/admin/tests/allures', name: 'admin_perf_test_allures', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_perf_test_allures')) {
            return $r;
        }

        // Saison demandée parmi celles qui ont des temps ; à défaut la plus récente (ou la saison en cours).
        $seasons = $this->sessions->findSeasonsWithResults();
        $requested = (int) $this->adminParam($request, 'season');
        $season = in_array($requested, $seasons, true)
            ? $requested
            : ($seasons[0] ?? PerfTestSessionRepository::seasonStartYear(new \DateTimeImmutable('today')));

        $recaps = [];
        foreach (PerfTestAllureService::TESTS as $test) {
            $recaps[] = $this->allures->seasonRecap($test, $season, null);
        }

        return $this->render('admin/perf_test_allures.html.twig', [
            'season' => $season,
            'seasonLabel' => PerfTestSessionRepository::seasonLabel($season),
            'seasons' => array_map(
                static fn (int $y) => ['year' => $y, 'label' => PerfTestSessionRepository::seasonLabel($y)],
                $seasons,
            ),
            'recaps' => $recaps,
        ]);
    }
}
