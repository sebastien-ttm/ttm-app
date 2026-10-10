<?php

namespace App\Controller\Admin;

use App\Entity\PerfTestDeclaration;
use App\Entity\User;
use App\Repository\PerfTestDeclarationRepository;
use App\Service\PerfTest\PerfTestDeclarationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Temps déclarés par les adhérents (prise de temps individuelle) : les
 * entraîneurs et l'admin les acceptent — le temps rejoint alors le
 * classement, sur une séance « individuelle » du jour — ou les refusent.
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

    public function __construct(
        private readonly PerfTestDeclarationRepository $declarations,
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

        return $this->render('admin/perf_test_declarations.html.twig', [
            'rows' => $this->declarations->findForAdmin($status, self::LIMIT),
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
            $error = $this->service->accept($declaration, $by, $note);
            if ($error !== null) {
                $this->addFlash('danger', $error);
            } else {
                $this->addFlash('success', sprintf(
                    'Temps accepté : %s — %s en %s (%s).',
                    $declaration->getUser()->getFullName(),
                    $declaration->getTestLabel(),
                    $declaration->getTimeLabel(),
                    $declaration->getPerformedOn()->format('d/m/Y'),
                ));
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
