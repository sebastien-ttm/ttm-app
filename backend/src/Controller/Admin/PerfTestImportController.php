<?php

namespace App\Controller\Admin;

use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Repository\PerfTestResultRepository;
use App\Repository\PerfTestSessionRepository;
use App\Repository\UserRepository;
use App\Service\PerfTest\PerfTestImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Import de temps collés depuis Excel dans une séance de test chronométré :
 * collage → aperçu (rapprochement des noms, anomalies) → validation.
 *
 * L'aperçu passe par la session HTTP puis une redirection (POST → GET) : la
 * page doit être rendue dans le contexte EasyAdmin, ce qu'un POST direct sur
 * une route custom ne permet pas.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class PerfTestImportController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'perf_test_import';
    private const MAX_TEXT_LENGTH = 200000;

    public function __construct(
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestResultRepository $results,
        private readonly UserRepository $users,
        private readonly PerfTestImportService $import,
    ) {
    }

    #[Route('/admin/tests/{id}/import', name: 'admin_perf_test_import', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function form(int $id, Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_perf_test_import')) {
            return $r;
        }
        $session = $this->findSession($id);
        $text = (string) $request->getSession()->get($this->sessionKey($id), '');

        $rows = null;
        $counts = [];
        if (trim($text) !== '') {
            $rows = $this->import->analyse(
                $session,
                $text,
                $this->users->findActiveAdherentsForRecap(),
                $this->results->findBySessionIndexedByUser($session),
            );
            $counts = array_count_values(array_column($rows, 'status'));
        }

        return $this->render('admin/perf_test_import.html.twig', [
            'session' => $session,
            'text' => $text,
            'textHash' => sha1($text),
            'rows' => $rows,
            'ready' => $counts['ok'] ?? 0,
            'toCheck' => ($counts['fuzzy'] ?? 0) + ($counts['ambiguous'] ?? 0),
            'ignored' => ($counts['unknown'] ?? 0) + ($counts['invalid'] ?? 0) + ($counts['duplicate'] ?? 0),
            'sheetUrl' => $this->forwardUrl('admin_perf_test_sheet', ['id' => $id]),
        ]);
    }

    /** Mémorise le texte collé puis affiche l'aperçu. */
    #[Route('/admin/tests/{id}/import/preview', name: 'admin_perf_test_import_preview', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function preview(int $id, Request $request): RedirectResponse
    {
        $this->denyUnlessCsrf($request);
        $this->findSession($id);

        $text = mb_substr((string) $request->request->get('text', ''), 0, self::MAX_TEXT_LENGTH);
        $request->getSession()->set($this->sessionKey($id), $text);
        if (trim($text) === '') {
            $this->addFlash('warning', 'Rien à analyser : collez d\'abord les lignes copiées depuis Excel.');
        }

        return $this->redirect($this->forwardUrl('admin_perf_test_import', ['id' => $id]));
    }

    /** Enregistre les temps de l'aperçu (lignes reconnues + lignes confirmées). */
    #[Route('/admin/tests/{id}/import/confirm', name: 'admin_perf_test_import_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(int $id, Request $request): RedirectResponse
    {
        $this->denyUnlessCsrf($request);
        $session = $this->findSession($id);

        $sessionKey = $this->sessionKey($id);
        $text = (string) $request->getSession()->get($sessionKey, '');
        if (trim($text) === '' || !hash_equals(sha1($text), (string) $request->request->get('text_hash', ''))) {
            $this->addFlash('danger', 'La liste a changé depuis l\'aperçu : relancez l\'analyse avant d\'importer.');
            return $this->redirect($this->forwardUrl('admin_perf_test_import', ['id' => $id]));
        }

        /** @var User|null $by */
        $by = $this->getUser();
        $summary = $this->import->commit(
            $session,
            $text,
            $this->users->findActiveAdherentsForRecap(),
            $this->results->findBySessionIndexedByUser($session),
            $request->request->all('choices'),
            $by instanceof User ? $by : null,
        );
        $request->getSession()->remove($sessionKey);

        $this->addFlash('success', sprintf(
            'Import terminé : %d temps ajouté(s), %d mis à jour, %d inchangé(s), %d ligne(s) ignorée(s).',
            $summary['created'],
            $summary['updated'],
            $summary['unchanged'],
            $summary['skipped'],
        ));

        return $this->redirect($this->forwardUrl('admin_perf_test_sheet', ['id' => $id]));
    }

    private function denyUnlessCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }

    private function sessionKey(int $id): string
    {
        return 'perf_test_import_'.$id;
    }

    private function findSession(int $id): PerfTestSession
    {
        return $this->sessions->find($id) ?? throw $this->createNotFoundException('Séance introuvable.');
    }

    /** URL « dashboard-forwardée » d'une route admin custom (garde le contexte EasyAdmin : menu, layout). */
    private function forwardUrl(string $routeName, array $params): string
    {
        return $this->generateUrl('admin_dashboard', [
            'routeName' => $routeName,
            'routeParams' => $params,
        ]);
    }
}
