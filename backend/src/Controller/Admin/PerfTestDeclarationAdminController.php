<?php

namespace App\Controller\Admin;

use App\Entity\PerfTestDeclaration;
use App\Entity\User;
use App\Repository\PerfTestDeclarationRepository;
use App\Repository\PerfTestSessionRepository;
use App\Service\PerfTest\PerfTestDeclarationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Temps déclarés par les adhérents (prise de temps individuelle) : les
 * entraîneurs et l'admin les acceptent — le temps rejoint alors une prise
 * de temps de l'épreuve (par défaut la plus récente, ou une plus ancienne
 * au choix) — ou les refusent.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class PerfTestDeclarationAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'perf_test_declaration';
    private const LIMIT = 200;
    private const STATUSES = [
        PerfTestDeclaration::STATUS_PENDING,
        PerfTestDeclaration::STATUS_ACCEPTED,
        PerfTestDeclaration::STATUS_REJECTED,
    ];

    /** Prises de temps proposées pour rattacher un temps accepté (la plus récente en premier). */
    private const SESSION_CHOICES = 15;

    public function __construct(
        private readonly PerfTestDeclarationRepository $declarations,
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestDeclarationService $service,
    ) {
    }

    #[Route('/admin/tests/declarations', name: 'admin_perf_test_declarations', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_perf_test_declarations')) {
            return $r;
        }

        $param = $this->adminParam($request, 'status');
        $status = in_array($param, self::STATUSES, true) ? $param : ($param === 'all' ? null : PerfTestDeclaration::STATUS_PENDING);
        $filter = $status ?? 'all';

        $rows = $this->declarations->findForAdmin($status, self::LIMIT);

        // Pour chaque demande en attente : les prises de temps de SON épreuve
        // (et bassin), la plus récente en tête = choix par défaut.
        $sessionsFor = [];
        $cache = [];
        foreach ($rows as $d) {
            if (!$d->isPending()) {
                continue;
            }
            $key = $d->getTest()->value.'_'.($d->getPoolLength() ?? 0);
            $cache[$key] ??= $this->sessions->findRecentForTest($d->getTest(), $d->getPoolLength(), self::SESSION_CHOICES);
            $sessionsFor[$d->getId()] = $cache[$key];
        }

        return $this->render('admin/perf_test_declarations.html.twig', [
            'rows' => $rows,
            'sessionsFor' => $sessionsFor,
            'filter' => $filter,
            'counts' => $this->declarations->countsByStatus(),
            'limit' => self::LIMIT,
        ]);
    }

    /** Accepter ou refuser une demande (champ `decision` = accept | reject, `note` facultative). */
    #[Route('/admin/tests/declarations/{id}/decision', name: 'admin_perf_test_declaration_decide', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $declaration = $this->declarations->find($id) ?? throw $this->createNotFoundException('Demande introuvable.');

        /** @var User $by */
        $by = $this->getUser();
        $note = (string) $request->request->get('note', '');
        $decision = (string) $request->request->get('decision', '');

        if ($decision === 'accept') {
            // Prise de temps choisie ; vide = la plus récente de l'épreuve (choix par défaut).
            $sessionId = (int) $request->request->get('session_id', 0);
            $target = $sessionId > 0 ? $this->sessions->find($sessionId) : null;
            if ($sessionId > 0 && $target === null) {
                $this->addFlash('danger', 'Prise de temps introuvable.');
            } else {
                $result = $this->service->accept($declaration, $by, $target, $note);
                if (is_string($result)) {
                    $this->addFlash('danger', $result);
                } else {
                    $this->addFlash('success', sprintf(
                        'Temps accepté : %s — %s en %s, ajouté à la prise de temps %s.',
                        $declaration->getUser()->getFullName(),
                        $declaration->getTestLabel(),
                        $declaration->getTimeLabel(),
                        $result->getDatesLabel(),
                    ));
                }
            }
        } elseif ($decision === 'reject') {
            $this->service->reject($declaration, $by, $note);
            $this->addFlash('success', sprintf('Demande de %s refusée.', $declaration->getUser()->getFullName()));
        }

        $filter = (string) $request->request->get('filter', PerfTestDeclaration::STATUS_PENDING);
        return $this->redirect($this->generateUrl('admin_dashboard', [
            'routeName' => 'admin_perf_test_declarations',
            'routeParams' => ['status' => in_array($filter, [...self::STATUSES, 'all'], true) ? $filter : PerfTestDeclaration::STATUS_PENDING],
        ]));
    }
}
