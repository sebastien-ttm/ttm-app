<?php

namespace App\Controller\Admin;

use App\Repository\LoginEventRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord stats backend : activité de connexion (mobile + admin),
 * comptes inactifs, graphique d'évolution sur 30 jours.
 *
 * Données dérivées de LoginEvent (historique fin alimenté par les
 * listeners de login) + counts directs sur User (jamais-connectés).
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class StatsController extends AbstractController
{
    public function __construct(
        private readonly LoginEventRepository $events,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/stats', name: 'admin_stats')]
    public function index(): Response
    {
        $now = new \DateTimeImmutable('now');
        $todayStart = new \DateTimeImmutable('today 00:00:00');
        $tomorrowStart = $todayStart->modify('+1 day');
        $weekStart = $todayStart->modify('-6 days');   // 7 jours glissants (J-6 → aujourd'hui)
        $monthStart = $todayStart->modify('-29 days'); // 30 jours glissants

        // KPIs
        $activeThisWeek = $this->events->countActiveUsersInRange($weekStart, $tomorrowStart);
        $loginsThisMonth = $this->events->countLoginsInRange($monthStart, $tomorrowStart);
        $activeThisMonth = $this->events->countActiveUsersInRange($monthStart, $tomorrowStart);
        $loginsToday = $this->events->countLoginsInRange($todayStart, $tomorrowStart);

        // Total des adhérents actifs + jamais connectés
        $em = $this->em;
        $totalActiveAccounts = (int) $em->createQuery(
            "SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.isActive = true"
        )->getSingleScalarResult();
        $neverLoggedIn = (int) $em->createQuery(
            "SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.isActive = true AND u.lastLoginAt IS NULL"
        )->getSingleScalarResult();

        // Série journalière pour le graphique (30 derniers jours, gap-filled à 0)
        $daily = $this->events->dailyCountsInRange($monthStart, $tomorrowStart);
        $series = [];
        $cursor = $monthStart;
        while ($cursor < $tomorrowStart) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'label' => $cursor->format('d/m'),
                'count' => $daily[$key] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $this->render('admin/stats.html.twig', [
            'kpis' => [
                'loginsToday' => $loginsToday,
                'activeThisWeek' => $activeThisWeek,
                'loginsThisMonth' => $loginsThisMonth,
                'activeThisMonth' => $activeThisMonth,
                'totalActiveAccounts' => $totalActiveAccounts,
                'neverLoggedIn' => $neverLoggedIn,
            ],
            'series' => $series,
            'now' => $now,
            'periodStart' => $monthStart,
        ]);
    }
}
