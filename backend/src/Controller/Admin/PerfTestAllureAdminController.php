<?php

namespace App\Controller\Admin;

use App\Repository\PerfTestSessionRepository;
use App\Service\PerfTest\PerfTestAllureService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Récap général des allures : pour une saison (sept. → août) et chacune des épreuves, les adhérents classés dans une
 * allure de 1 à 5 d'après leur meilleur temps de la saison, avec la séance où il a été réalisé. Natation : un temps en
 * bassin de 50 m est diminué de 10 secondes pour le classement (le temps réel et la mention sont affichés).
 *
 * Les intervalles sont propres à chaque saison (menu « Intervalles d'allure ») : la page indique ceux qui s'appliquent,
 * compte les adhérents de chaque allure pour les équilibrer, et permet de reprendre ceux d'une autre saison pour démarrer.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class PerfTestAllureAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'perf_test_allure_copy';

    public function __construct(
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestAllureService $allures,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/tests/allures', name: 'admin_perf_test_allures', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_perf_test_allures')) {
            return $r;
        }

        // Saison demandée parmi celles qui ont des temps ; à défaut la plus récente (ou la saison en cours).
        $current = PerfTestSessionRepository::seasonStartYear(new \DateTimeImmutable('today'));
        $seasons = $this->sessions->findSeasonsWithResults();
        // La saison en cours figure toujours dans le sélecteur, même sans temps : c'est là qu'on prépare ses intervalles.
        if (!in_array($current, $seasons, true)) {
            $seasons[] = $current;
            rsort($seasons);
        }
        $requested = (int) $this->adminParam($request, 'season');
        $season = in_array($requested, $seasons, true) ? $requested : $seasons[0];

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
            // Saisons dont on peut reprendre les intervalles (hors celle affichée).
            'copySources' => array_map(
                static fn (int $y) => ['year' => $y, 'label' => PerfTestSessionRepository::seasonLabel($y)],
                array_values(array_filter($this->allures->seasonsWithScales(), static fn (int $y) => $y !== $season)),
            ),
            'paceUrl' => $this->adminUrlGenerator->unsetAll()->setController(PerfTestPaceCrudController::class)->setAction('index')->generateUrl(),
        ]);
    }

    /** Reprend, pour la saison `to`, les intervalles de la saison `from` (ceux que `to` avait déjà sont remplacés). */
    #[Route('/admin/tests/allures/copier', name: 'admin_perf_test_allures_copy', methods: ['POST'])]
    public function copy(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $from = (int) $request->request->get('from', 0);
        $to = (int) $request->request->get('to', 0);
        if ($from < 2000 || $from > 2100 || $to < 2000 || $to > 2100 || $from === $to) {
            $this->addFlash('danger', 'Choisissez une autre saison à copier.');

            return $this->redirectToRoute('admin_perf_test_allures', ['season' => $to ?: null]);
        }

        $this->allures->copySeason($from, $to);
        $this->addFlash('success', sprintf(
            'Intervalles de la saison %s repris pour la saison %s : ajustez-les dans « Intervalles d\'allure ».',
            PerfTestSessionRepository::seasonLabel($from),
            PerfTestSessionRepository::seasonLabel($to),
        ));

        return $this->redirectToRoute('admin_perf_test_allures', ['season' => $to]);
    }
}
