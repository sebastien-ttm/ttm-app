<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Repository\UserSeasonMembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ancienneté des adhérents : classement par nombre de saisons d'adhésion (imports de la saison courante et imports
 * historiques, voir UserSeasonMembership), avec première et dernière saison et l'appartenance à la saison en cours.
 * Les ex æquo partagent le même rang. Par défaut les 50 premiers rangs ; `?all=1` pour tout le monde.
 */
#[IsGranted('ROLE_ADMIN')]
class AdherentSeniorityController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
    ) {
    }

    #[Route('/admin/adherents/anciennete', name: 'admin_adherents_seniority', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_adherents_seniority')) {
            return $r;
        }
        $showAll = $this->adminParam($request, 'all') === '1';

        $seniority = $this->memberships->seniorityByUser();
        $users = [];
        foreach ($this->users->findBy(['id' => array_keys($seniority)]) as $u) {
            $users[(int) $u->getId()] = $u;
        }
        $current = $this->seasons->findCurrent();
        $currentIds = $current === null ? [] : array_flip($this->memberships->findUserIdsForSeason($current));

        $rows = [];
        foreach ($seniority as $id => $s) {
            $u = $users[$id] ?? null;
            if ($u === null) {
                continue;
            }
            $rows[] = $s + ['user' => $u, 'current' => isset($currentIds[$id])];
        }
        // Plus de saisons d'abord ; à égalité, la plus ancienne première saison, puis par nom.
        usort($rows, static fn (array $a, array $b) => [$b['seasons'], $a['firstYear'] ?? PHP_INT_MAX, mb_strtolower($a['user']->getNom()), mb_strtolower($a['user']->getPrenom())]
            <=> [$a['seasons'], $b['firstYear'] ?? PHP_INT_MAX, mb_strtolower($b['user']->getNom()), mb_strtolower($b['user']->getPrenom())]);

        // Rang « olympique » : les ex æquo (même nombre de saisons) partagent le rang.
        $rank = 0;
        $previous = null;
        foreach ($rows as $i => &$row) {
            if ($row['seasons'] !== $previous) {
                $rank = $i + 1;
                $previous = $row['seasons'];
            }
            $row['rank'] = $rank;
        }
        unset($row);

        $total = count($rows);
        if (!$showAll) {
            $rows = array_values(array_filter($rows, static fn (array $r) => $r['rank'] <= self::DEFAULT_LIMIT));
        }

        // Répartition : combien d'adhérents ont 1, 2, 3… saisons.
        $distribution = [];
        foreach ($seniority as $id => $s) {
            if (isset($users[$id])) {
                $distribution[$s['seasons']] = ($distribution[$s['seasons']] ?? 0) + 1;
            }
        }
        krsort($distribution);

        return $this->render('admin/adherents_seniority.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'showAll' => $showAll,
            'limit' => self::DEFAULT_LIMIT,
            'distribution' => $distribution,
            'currentSeason' => $current,
            'seasonCount' => count($this->seasons->findAll()),
        ]);
    }
}
