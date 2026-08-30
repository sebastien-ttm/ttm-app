<?php

namespace App\Controller\Admin;

use App\Message\SendInvoiceEmailMessage;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Service\Invoice\InvoiceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Actions admin sur les factures d'adhésion :
 *  - GET  /admin/invoice/{userId}/{seasonId}          → PDF inline (preview)
 *  - POST /admin/invoice/{userId}/{seasonId}/email    → dispatch email au user
 */
#[IsGranted('ROLE_ADMIN')]
class InvoiceActionController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
        private readonly InvoiceService $invoiceService,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route(
        '/admin/invoice/{userId}/{seasonId}',
        name: 'admin_invoice_pdf',
        methods: ['GET'],
        requirements: ['userId' => '\d+', 'seasonId' => '\d+'],
    )]
    public function pdf(int $userId, int $seasonId): Response
    {
        $user = $this->users->find($userId);
        $season = $this->seasons->find($seasonId);
        if ($user === null || $season === null) {
            throw $this->createNotFoundException();
        }
        try {
            $pdf = $this->invoiceService->renderPdf($user, $season);
        } catch (\RuntimeException $e) {
            return new Response('<pre style="padding:20px;font-family:monospace;color:#991b1b;">'
                .htmlspecialchars($e->getMessage()).'</pre>', 500);
        }
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->invoiceService->suggestedFilename($user, $season).'"',
        ]);
    }

    #[Route(
        '/admin/invoice/{userId}/{seasonId}/email',
        name: 'admin_invoice_email',
        methods: ['POST'],
        requirements: ['userId' => '\d+', 'seasonId' => '\d+'],
    )]
    public function sendByEmail(int $userId, int $seasonId): RedirectResponse
    {
        $user = $this->users->find($userId);
        $season = $this->seasons->find($seasonId);
        if ($user === null || $season === null) {
            throw $this->createNotFoundException();
        }
        if (!$user->getEmail()) {
            $this->addFlash('error', 'Cet adhérent n\'a pas d\'e-mail — impossible d\'envoyer.');
            return $this->redirectToRoute('admin_invoice_pdf', ['userId' => $userId, 'seasonId' => $seasonId]);
        }
        $this->bus->dispatch(new SendInvoiceEmailMessage($userId, $seasonId));
        $this->addFlash('success', sprintf(
            'Facture programmée pour envoi à %s.', $user->getEmail(),
        ));
        return $this->redirectToRoute('admin_invoice_pdf', ['userId' => $userId, 'seasonId' => $seasonId]);
    }
}
