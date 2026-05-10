<?php

namespace App\Controller\Admin;

use App\Entity\CharterAcceptance;
use App\Entity\User;
use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-side equivalent of the mobile charter acceptance screen :
 * displays the current charter and accepts via a POST form.
 */
#[IsGranted('ROLE_COACH')]
class AdminCharterAcceptanceController extends AbstractController
{
    public function __construct(
        private readonly ClubCharterRepository $charters,
        private readonly CharterAcceptanceRepository $acceptances,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/charter/accept', name: 'admin_charter_accept')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $charter = $this->charters->findCurrent();

        if ($charter === null) {
            // No active charter → nothing to accept, just bounce to dashboard
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->acceptances->hasAccepted($user, $charter)) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($request->isMethod('POST') && $request->request->get('accepted') === '1') {
            $this->em->persist(new CharterAcceptance($user, $charter, $request->getClientIp()));
            $this->em->flush();
            return new RedirectResponse($this->generateUrl('admin_dashboard'));
        }

        return $this->render('admin/charter_accept.html.twig', [
            'charter' => $charter,
        ]);
    }
}
