<?php

namespace App\Controller\Admin;

use App\Entity\MembershipFee;
use App\Entity\User;
use App\Enum\PaymentType;
use App\Repository\UserRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Invoice\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page dédiée aux adhésions par saison d'un utilisateur :
 *  - liste des UserSeasonMembership
 *  - édition du mode de paiement
 *  - actions Facture (voir PDF / envoyer par email)
 */
#[IsGranted('ROLE_ADMIN')]
class UserMembershipsController extends AbstractController
{
    use EnsureAdminContextTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly InvoiceService $invoiceService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/adherents/{id}/memberships', name: 'admin_user_memberships', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function index(int $id, Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_user_memberships')) {
            return $r;
        }
        $user = $this->users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        $rows = $this->em->createQuery(
            'SELECT m FROM App\Entity\UserSeasonMembership m
             JOIN m.season s
             WHERE m.user = :u
             ORDER BY s.startsAt DESC'
        )->setParameter('u', $user)->getResult();

        // Pour chaque membership, tente de calculer le tarif applicable
        $withFee = [];
        foreach ($rows as $m) {
            $r = $this->invoiceService->resolveFee($user, $m->getSeason(), $m);
            $withFee[] = [
                'membership' => $m,
                'profile' => $r['profile'],
                'typeLicence' => $r['typeLicence'],
                'fee' => $r['fee'],
                'paymentLabel' => (PaymentType::tryFrom($m->getPaymentType()) ?? PaymentType::CB)->label(),
            ];
        }

        return $this->render('admin/user_memberships.html.twig', [
            'user' => $user,
            'rows' => $withFee,
            'paymentChoices' => PaymentType::cases(),
            'tariffChoices' => MembershipFee::APPLICABLE_PROFILES,
        ]);
    }

    #[Route('/admin/adherents/{id}/memberships/{mid}/tariff', name: 'admin_user_membership_tariff', methods: ['POST'], requirements: ['id' => '\d+', 'mid' => '\d+'])]
    public function setTariff(int $id, int $mid, Request $request): RedirectResponse
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('membership_tariff', $token)) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $membership = $this->memberships->find($mid);
        if ($membership === null || $membership->getUser()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        $raw = (string) $request->request->get('tariff_profile', '');
        // Chaîne vide = « auto » (reset override).
        if ($raw === '') {
            $membership->setTariffProfile(null);
        } elseif (in_array($raw, MembershipFee::APPLICABLE_PROFILES, true)) {
            $membership->setTariffProfile($raw);
        } else {
            $this->addFlash('error', 'Profil tarifaire invalide.');
            return $this->redirectToRoute('admin_user_memberships', ['id' => $id]);
        }
        $this->em->flush();
        $this->addFlash('success', 'Profil tarifaire mis à jour.');
        return $this->redirectToRoute('admin_user_memberships', ['id' => $id]);
    }

    #[Route('/admin/adherents/{id}/memberships/{mid}/payment', name: 'admin_user_membership_payment', methods: ['POST'], requirements: ['id' => '\d+', 'mid' => '\d+'])]
    public function setPayment(int $id, int $mid, Request $request): RedirectResponse
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('membership_payment', $token)) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $membership = $this->memberships->find($mid);
        if ($membership === null || $membership->getUser()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        $type = (string) $request->request->get('payment_type', '');
        $pt = PaymentType::tryFrom($type);
        if ($pt === null) {
            $this->addFlash('error', 'Mode de paiement invalide.');
            return $this->redirectToRoute('admin_user_memberships', ['id' => $id]);
        }
        $membership->setPaymentType($pt->value);
        $this->em->flush();
        $this->addFlash('success', 'Mode de paiement mis à jour : '.$pt->label());
        return $this->redirectToRoute('admin_user_memberships', ['id' => $id]);
    }
}
