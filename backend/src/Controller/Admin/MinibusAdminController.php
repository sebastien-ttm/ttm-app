<?php

namespace App\Controller\Admin;

use App\Entity\MinibusBooking;
use App\Entity\MinibusCheckPhoto;
use App\Entity\User;
use App\Enum\MinibusCheckType;
use App\Repository\MinibusBookingRepository;
use App\Security\MinibusVoter;
use App\Service\Minibus\MinibusException;
use App\Service\Minibus\MinibusPhotoService;
use App\Service\Minibus\MinibusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Prêt du minibus — vue du back-office (gestionnaire du minibus et administrateurs) : les demandes d'emprunt à traiter en
 * premier, puis toutes les autres avec leur état des lieux de départ et de retour (photos comprises). On y valide ou
 * refuse une demande (avec un message à l'emprunteur), on annule un emprunt ; seuls les administrateurs suppriment
 * définitivement une demande. Les mêmes actions existent dans l'appli (MinibusController) ; les règles sont dans
 * MinibusService. La charte d'utilisation se modifie dans « Charte du minibus » (MinibusSettingsCrudController).
 */
#[IsGranted(MinibusVoter::MANAGE)]
class MinibusAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF = 'minibus_admin';

    public function __construct(
        private readonly MinibusBookingRepository $bookings,
        private readonly MinibusService $service,
        private readonly MinibusPhotoService $photos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/minibus', name: 'admin_minibus', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_minibus')) {
            return $r;
        }
        $all = $this->bookings->findAllForAdmin();
        $conflicts = [];
        foreach ($all as $booking) {
            if ($booking->isPending()) {
                $conflicts[(int) $booking->getId()] = $this->service->conflictsOf($booking);
            }
        }

        return $this->render('admin/minibus.html.twig', [
            'bookings' => $all,
            'conflicts' => $conflicts,
            'pendingCount' => count(array_filter($all, static fn (MinibusBooking $b) => $b->isPending())),
            'checkTypes' => MinibusCheckType::cases(),
            'csrf' => self::CSRF,
        ]);
    }

    /** Valide (`decision=approve`) ou refuse (`decision=refuse`) une demande en attente ; `note` : message à l'emprunteur. */
    #[Route('/admin/minibus/{id}/decision', name: 'admin_minibus_decision', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $booking = $this->findOr404($id);
        $manager = $this->getUser();
        $note = mb_substr(trim((string) $request->request->get('note', '')), 0, 1000);

        try {
            if ($manager instanceof User) {
                match ((string) $request->request->get('decision', '')) {
                    'approve' => $this->service->approve($booking, $manager, $note),
                    'refuse' => $this->service->refuse($booking, $manager, $note),
                    default => throw MinibusException::invalid('Décision inconnue.'),
                };
                $this->addFlash('success', $booking->isApproved() ? 'Demande validée.' : 'Demande refusée.');
            }
        } catch (MinibusException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backTo($booking);
    }

    #[Route('/admin/minibus/{id}/cancel', name: 'admin_minibus_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $booking = $this->findOr404($id);
        $user = $this->getUser();

        try {
            if ($user instanceof User) {
                $this->service->cancel($booking, $user, true);
                $this->addFlash('success', 'Demande annulée.');
            }
        } catch (MinibusException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backTo($booking);
    }

    /** Suppression définitive (administrateurs) : la demande, ses états des lieux et leurs photos. */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/minibus/{id}/delete', name: 'admin_minibus_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $this->service->remove($this->findOr404($id));
        $this->addFlash('success', 'Demande supprimée.');

        return $this->redirectToRoute('admin_minibus');
    }

    /** Photo d'un état des lieux (session du back-office). */
    #[Route('/admin/minibus/photos/{id}', name: 'admin_minibus_photo', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function photo(int $id): Response
    {
        $photo = $this->em->find(MinibusCheckPhoto::class, $id);
        $response = $photo === null ? null : $this->photos->response($photo);
        if ($response === null) {
            throw $this->createNotFoundException();
        }

        return $response;
    }

    private function findOr404(int $id): MinibusBooking
    {
        $booking = $this->bookings->find($id);
        if ($booking === null) {
            throw $this->createNotFoundException();
        }

        return $booking;
    }

    private function validateCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }

    private function backTo(MinibusBooking $booking): RedirectResponse
    {
        return $this->redirect($this->generateUrl('admin_minibus').'#booking-'.$booking->getId());
    }
}
