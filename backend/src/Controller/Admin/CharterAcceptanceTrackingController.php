<?php

namespace App\Controller\Admin;

use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CharterAcceptanceTrackingController extends AbstractController
{
    public function __construct(
        private readonly ClubCharterRepository $charters,
        private readonly CharterAcceptanceRepository $acceptances,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/admin/charter/tracking', name: 'admin_charter_tracking')]
    public function index(): Response
    {
        $charter = $this->charters->findCurrent();
        $accepted = [];
        $missing = [];

        if ($charter !== null) {
            foreach ($charter->getAcceptances() as $acc) {
                $accepted[] = [
                    'user' => $acc->getUser(),
                    'acceptedAt' => $acc->getAcceptedAt(),
                    'ipAddress' => $acc->getIpAddress(),
                ];
            }
            $missingIds = $this->acceptances->findMissingAcceptances($charter);
            foreach ($missingIds as $id) {
                $u = $this->users->find($id);
                if ($u !== null) {
                    $missing[] = $u;
                }
            }
        }

        return $this->render('admin/charter_tracking.html.twig', [
            'charter' => $charter,
            'accepted' => $accepted,
            'missing' => $missing,
        ]);
    }
}
