<?php

namespace App\Controller\Admin;

use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Repository\PerfTestResultRepository;
use App\Repository\PerfTestSessionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Feuille de saisie des temps d'une séance de test : un champ par
 * adhérent actif (recherche par nom), enregistrement immédiat sans
 * rechargement, rappel du dernier temps et du record de l'adhérent
 * sur la même épreuve. Export CSV classé.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class PerfTestResultController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'perf_test';

    public function __construct(
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestResultRepository $results,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/tests/{id}/temps', name: 'admin_perf_test_sheet', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sheet(int $id, Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_perf_test_sheet')) {
            return $r;
        }
        $session = $this->findSession($id);
        $results = $this->results->findBySessionIndexedByUser($session);
        $history = $this->results->findHistoryBefore($session);

        /** @var array<int, User> $people */
        $people = [];
        foreach ($this->users->findActiveAdherentsForRecap() as $u) {
            $people[$u->getId()] = $u;
        }
        // Adhérent devenu inactif mais déjà chronométré sur cette séance.
        foreach ($results as $userId => $r) {
            $people[$userId] ??= $r->getUser();
        }

        $rows = [];
        foreach ($people as $userId => $u) {
            $h = $history[$userId] ?? null;
            $rows[] = [
                'id' => $userId,
                'nom' => $u->getNom(),
                'prenom' => $u->getPrenom(),
                'categorie' => $u->getCategorieFFTri(),
                'state' => self::state($results[$userId] ?? null),
                'last' => $h ? [
                    'time' => PerfTestResult::format($h['last']->getTimeSeconds()),
                    'seconds' => $h['last']->getTimeSeconds(),
                    'date' => $h['last']->getSession()->getDatesLabel(),
                ] : null,
                'best' => $h ? [
                    'time' => PerfTestResult::format($h['best']->getTimeSeconds()),
                    'seconds' => $h['best']->getTimeSeconds(),
                ] : null,
            ];
        }
        usort($rows, fn ($a, $b) => [mb_strtolower($a['nom']), mb_strtolower($a['prenom'])] <=> [mb_strtolower($b['nom']), mb_strtolower($b['prenom'])]);

        return $this->render('admin/perf_test_sheet.html.twig', [
            'session' => $session,
            'rows' => $rows,
            'enteredCount' => count($results),
            // Anciens adhérents (sans compte) importés : lecture seule, suppression possible.
            'legacy' => array_map(static fn (PerfTestResult $r) => [
                'id' => $r->getId(),
                'name' => $r->getLegacyName(),
                'time' => PerfTestResult::format($r->getTimeSeconds()),
            ], $this->results->findLegacyBySession($session)),
            'csvUrl' => $this->adminRoute('admin_perf_test_csv', ['id' => $session->getId()]),
            'importUrl' => $this->adminRoute('admin_perf_test_import', ['id' => $session->getId()]),
            'indexUrl' => $this->adminUrlGenerator->unsetAll()
                ->setController(PerfTestSessionCrudController::class)->generateUrl(),
        ]);
    }

    /** Enregistre (ou efface, si vide) le temps d'un adhérent. */
    #[Route('/admin/tests/{id}/temps/{userId}', name: 'admin_perf_test_save', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function save(int $id, int $userId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $session = $this->findSession($id);
        $user = $this->users->find($userId);
        if ($user === null) {
            throw $this->createNotFoundException('Adhérent introuvable.');
        }

        $raw = trim((string) $request->request->get('time', ''));
        $result = $this->results->findOneBySessionAndUser($session, $user);

        if ($raw === '') {
            if ($result !== null) {
                $this->em->remove($result);
                $this->em->flush();
            }
            return new JsonResponse(self::state(null));
        }

        $seconds = PerfTestResult::parse($raw);
        if ($seconds === null) {
            return new JsonResponse(
                ['error' => 'Temps illisible : saisissez par ex. 5:42 (min:s) ou 1:02:15 (h:min:s).'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User $by */
        $by = $this->getUser();
        if ($result === null) {
            $result = new PerfTestResult($session, $user, $seconds, $by);
            $this->em->persist($result);
        } else {
            $result->setTime($seconds, $by);
        }
        $this->em->flush();

        return new JsonResponse(self::state($result));
    }

    /** Supprime le temps d'un ancien adhérent (sans compte) importé par erreur. */
    #[Route('/admin/tests/{id}/anciens/{resultId}/supprimer', name: 'admin_perf_test_legacy_delete', methods: ['POST'], requirements: ['id' => '\d+', 'resultId' => '\d+'])]
    public function deleteLegacy(int $id, int $resultId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $session = $this->findSession($id);
        $result = $this->results->find($resultId);
        // Uniquement un temps d'ancien adhérent de CETTE séance (jamais celui d'un adhérent).
        if ($result !== null && $result->getSession()->getId() === $session->getId() && $result->getUser() === null) {
            $name = $result->getLegacyName();
            $this->em->remove($result);
            $this->em->flush();
            $this->addFlash('success', sprintf('Temps de « %s » supprimé.', $name));
        }

        return $this->redirect($this->generateUrl('admin_dashboard', [
            'routeName' => 'admin_perf_test_sheet',
            'routeParams' => ['id' => $id],
        ]));
    }

    /** Temps de la séance, du plus rapide au plus lent. */
    #[Route('/admin/tests/{id}/temps.csv', name: 'admin_perf_test_csv', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function csv(int $id): StreamedResponse
    {
        $session = $this->findSession($id);
        // Adhérents ET anciens adhérents (sans compte), du plus rapide au plus lent.
        $results = $this->results->findAllBySession($session);
        $history = $this->results->findHistoryBefore($session);

        $response = new StreamedResponse(function () use ($session, $results, $history): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Épreuve', $session->getTestLabel()], ';');
            fputcsv($out, ['Période', $session->getDatesLabel()], ';');
            if ($session->getNotes()) {
                fputcsv($out, ['Notes', $session->getNotes()], ';');
            }
            fputcsv($out, [], ';');
            fputcsv($out, ['Rang', 'Nom', 'Prénom', 'Catégorie', 'Temps', 'Secondes', 'Temps précédent', 'Écart (s)', 'Record précédent'], ';');
            foreach ($results as $i => $r) {
                $u = $r->getUser();
                $h = $u !== null ? ($history[$u->getId()] ?? null) : null;
                fputcsv($out, [
                    $i + 1,
                    $u !== null ? $u->getNom() : $r->getLegacyName(),
                    $u !== null ? $u->getPrenom() : '',
                    $u !== null ? $u->getCategorieFFTri() : 'ancien adhérent',
                    PerfTestResult::format($r->getTimeSeconds()),
                    $r->getTimeSeconds(),
                    $h ? PerfTestResult::format($h['last']->getTimeSeconds()) : '',
                    $h ? $r->getTimeSeconds() - $h['last']->getTimeSeconds() : '',
                    $h ? PerfTestResult::format($h['best']->getTimeSeconds()) : '',
                ], ';');
            }
            fclose($out);
        });

        $filename = sprintf('test-%s-%s.csv', $session->getTest()->value, $session->getDate()->format('Ymd'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;
    }

    private function findSession(int $id): PerfTestSession
    {
        return $this->sessions->find($id) ?? throw $this->createNotFoundException('Séance introuvable.');
    }

    /** @return array{seconds: ?int, time: ?string, by: ?string} */
    private static function state(?PerfTestResult $r): array
    {
        return [
            'seconds' => $r?->getTimeSeconds(),
            'time' => $r !== null ? PerfTestResult::format($r->getTimeSeconds()) : null,
            'by' => $r?->getEnteredBy()?->getFullName(),
        ];
    }

    /** Voir doc dans EventAttendanceReportController::adminRoute(). */
    private function adminRoute(string $routeName, array $params = []): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setRoute($routeName, $params)
            ->generateUrl();
    }
}
