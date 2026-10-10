<?php

namespace App\Controller\Admin;

use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Membership\ExternalMemberService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Adhérents externes (licenciés dans un autre club, comptes créés depuis l'appli) : liste
 * et activation pour la saison en cours. Sans activation, l'import des licences les
 * désactive une fois la date limite des anciens adhérents passée, car ils ne figurent pas
 * dans le CSV FFTri du club (voir ExternalMemberService).
 */
#[IsGranted('ROLE_ADMIN')]
class ExternalMembersAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'external_members';
    private const VIEWS = ['todo', 'done', 'all'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly TrainingSeasonRepository $seasons,
        private readonly ExternalMemberService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/adherents-externes', name: 'admin_external_members', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_external_members')) {
            return $r;
        }

        $season = $this->seasons->findCurrent();
        $activatedIds = $season !== null ? array_flip($this->memberships->findUserIdsForSeason($season)) : [];

        $all = [];
        $counts = ['todo' => 0, 'done' => 0, 'all' => 0];
        foreach ($this->users->findExternalMembers() as $user) {
            $activated = isset($activatedIds[$user->getId()]);
            $all[] = ['user' => $user, 'activated' => $activated];
            ++$counts['all'];
            ++$counts[$activated ? 'done' : 'todo'];
        }

        $param = $this->adminParam($request, 'view');
        $view = in_array($param, self::VIEWS, true) ? $param : 'todo';
        $rows = array_values(array_filter(
            $all,
            static fn (array $row) => $view === 'all' || ($view === 'done') === $row['activated'],
        ));

        return $this->render('admin/external_members.html.twig', [
            'season' => $season,
            'rows' => $rows,
            'counts' => $counts,
            'view' => $view,
        ]);
    }

    /** Active les adhérents externes cochés (ou un seul : `ids` contient alors son identifiant). */
    #[Route('/admin/adherents-externes/activer', name: 'admin_external_members_activate', methods: ['POST'])]
    public function activate(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $view = (string) $request->request->get('view', 'todo');
        $season = $this->seasons->findCurrent();
        if ($season === null) {
            $this->addFlash('danger', 'Aucune saison d\'entraînement n\'est définie : créez-la d\'abord (Entraînements → Saison d\'entraînement).');

            return $this->back($view);
        }

        $ids = array_values(array_filter(array_map('intval', $request->request->all('ids'))));
        if ($ids === []) {
            $this->addFlash('warning', 'Aucun adhérent externe sélectionné.');

            return $this->back($view);
        }

        $changed = 0;
        foreach ($ids as $id) {
            $user = $this->users->find($id);
            if ($user === null) {
                continue;
            }
            try {
                if ($this->service->activate($user, $season)) {
                    ++$changed;
                }
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%d adhérent(s) externe(s) activé(s) pour la saison %s.',
            $changed,
            (string) $season,
        ));

        return $this->back($view);
    }

    private function back(string $view): RedirectResponse
    {
        return $this->redirect($this->generateUrl('admin_dashboard', [
            'routeName' => 'admin_external_members',
            'routeParams' => ['view' => in_array($view, self::VIEWS, true) ? $view : 'todo'],
        ]));
    }
}
