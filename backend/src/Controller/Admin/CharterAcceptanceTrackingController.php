<?php

namespace App\Controller\Admin;

use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrlGenerator,
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
                    'id' => $acc->getId(),
                    'user' => $acc->getUser(),
                    'acceptedAt' => $acc->getAcceptedAt(),
                    'ipAddress' => $acc->getIpAddress(),
                    'revokeUrl' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setRoute('admin_charter_tracking_revoke', ['id' => $acc->getId()])
                        ->generateUrl(),
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

    /**
     * Retire une acceptation de charte. Usage : correction d'une
     * acceptation faite par erreur (ex : bascule sur le profil d'un
     * enfant non-renouvelé pendant une période où le tunnel s'affichait
     * à tort). L'user pourra à nouveau signer si son statut le justifie.
     *
     * POST-only pour éviter les revokes accidentels via un GET en cache.
     */
    #[Route('/admin/charter/tracking/revoke/{id}', name: 'admin_charter_tracking_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, Request $request): RedirectResponse
    {
        $acc = $this->acceptances->find($id);
        if ($acc === null) {
            $this->addFlash('warning', 'Acceptation introuvable.');
        } else {
            $userLabel = $acc->getUser()->getFullName();
            $this->em->remove($acc);
            $this->em->flush();
            $this->addFlash('success', sprintf('Acceptation retirée pour %s.', $userLabel));
        }

        return $this->redirect($this->adminUrlGenerator
            ->unsetAll()
            ->setRoute('admin_charter_tracking')
            ->generateUrl());
    }
}
