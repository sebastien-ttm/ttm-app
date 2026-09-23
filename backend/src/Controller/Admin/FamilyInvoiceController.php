<?php

namespace App\Controller\Admin;

use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Enum\PaymentType;
use App\Service\Invoice\InvoiceService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Génération d'une facture « famille » : une seule facture au nom
 * d'un adhérent principal, avec plusieurs lignes (une par personne
 * de la famille) et un montant modifiable pour chacune — pratique
 * pour appliquer une réduction famille.
 *
 * Deux étapes :
 *   1. GET  /admin/invoice/family                → choix de l'adhérent principal
 *   2. GET  /admin/invoice/family/{userId}       → formulaire lignes + montants
 *   3. POST /admin/invoice/family/{userId}       → génère et sert le PDF
 */
#[IsGranted('ROLE_ADMIN')]
class FamilyInvoiceController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
        private readonly InvoiceService $invoiceService,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * Étape 1 : sélection de l'adhérent principal.
     */
    #[Route('/admin/invoice/family', name: 'admin_invoice_family_pick')]
    public function pick(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $rows = [];
        if ($q !== '' && mb_strlen($q) >= 2) {
            // Filtre : adhérent (type=Adherent) OU parent externe
            // (type=Externe + subType=parent). Les autres comptes externes
            // (ami…) et amis du club ne peuvent pas être facturés.
            $matches = $this->users->createQueryBuilder('u')
                ->where('u.isActive = true')
                ->andWhere('u.nom LIKE :q OR u.prenom LIKE :q OR u.email LIKE :q OR u.numLicence LIKE :q')
                ->andWhere("u.type = 'adherent' OR (u.type = 'externe' AND u.subType = 'parent')")
                ->setParameter('q', '%'.$q.'%')
                ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
                ->setMaxResults(20)
                ->getQuery()->getResult();
            // URLs Symfony natives — le controller build() se ré-enrichit
            // du contexte EA au premier accès (redirect vers l'URL avec
            // params dashboard), même pattern que CsvImportController.
            foreach ($matches as $u) {
                $rows[] = [
                    'user' => $u,
                    'buildUrl' => $this->generateUrl('admin_invoice_family_build', ['userId' => $u->getId()]),
                ];
            }
        }
        return $this->render('admin/family_invoice_pick.html.twig', [
            'q' => $q,
            'rows' => $rows,
            'formAction' => $this->generateUrl('admin_invoice_family_pick'),
        ]);
    }

    /**
     * Étape 2/3 : formulaire des lignes + génération du PDF.
     * GET  = affiche le formulaire, montants pré-remplis via
     *        `InvoiceService::suggestedAmountCents`.
     * POST = génère le PDF avec les lignes cochées / montants édités.
     */
    #[Route('/admin/invoice/family/{userId}', name: 'admin_invoice_family_build', requirements: ['userId' => '\d+'])]
    public function build(int $userId, Request $request): Response
    {
        $primary = $this->users->find($userId);
        if ($primary === null) {
            throw $this->createNotFoundException();
        }
        $season = $this->seasons->findCurrent();
        if ($season === null) {
            $this->addFlash('warning', 'Aucune saison configurée — configurez d\'abord une TrainingSeason.');
            return $this->redirectToRoute('admin_invoice_family_pick');
        }

        // Candidats : le primaire + les profils liés (email/famille) actifs.
        $candidates = [$primary];
        foreach ($this->users->findLinkedProfiles($primary) as $u) {
            if ($u->getId() === $primary->getId() || !$u->isActive()) continue;
            $candidates[] = $u;
        }

        if ($request->isMethod('POST')) {
            $selectedIds = $request->request->all('include');   // array<int, string>
            $amountsEur = $request->request->all('amount_eur'); // array<userId, string>
            $action = (string) $request->request->get('action', 'pdf'); // 'pdf' | 'email'
            $paymentType = PaymentType::tryFrom((string) $request->request->get('payment_type', 'cb')) ?? PaymentType::CB;
            $lines = [];
            foreach ($candidates as $u) {
                $uid = (string) $u->getId();
                if (!isset($selectedIds[$uid])) continue;
                $raw = (string) ($amountsEur[$uid] ?? '');
                $normalized = str_replace([',', ' '], ['.', ''], trim($raw));
                if ($normalized === '' || !is_numeric($normalized)) continue;
                $cents = (int) round(((float) $normalized) * 100);
                $lines[] = ['user' => $u, 'amountCents' => max(0, $cents)];
            }
            if ($lines === []) {
                $this->addFlash('warning', 'Sélectionnez au moins une personne à facturer.');
            } else {
                try {
                    $pdf = $this->invoiceService->renderFamilyPdf($primary, $lines, $season, $paymentType);
                } catch (\RuntimeException $e) {
                    return new Response('<pre style="padding:20px;font-family:monospace;color:#991b1b;">'
                        .htmlspecialchars($e->getMessage()).'</pre>', 500);
                }
                $filename = $this->invoiceService->suggestedFamilyFilename($primary, $season, $paymentType);
                $isAttestation = $paymentType->isCollectedByFftri();

                if ($action === 'email') {
                    if (!$primary->getEmail()) {
                        $this->addFlash('error', 'Impossible d\'envoyer : l\'adhérent principal n\'a pas d\'e-mail.');
                    } else {
                        $mail = (new TemplatedEmail())
                            ->to($primary->getEmail())
                            ->subject($isAttestation
                                ? sprintf('Votre attestation de paiement famille — Saison %s', (string) $season)
                                : sprintf('Votre facture d\'adhésion famille — Saison %s', (string) $season))
                            ->htmlTemplate('email/invoice.html.twig')
                            ->textTemplate('email/invoice.txt.twig')
                            ->context([
                                'user' => $primary,
                                'season' => $season,
                                'isAttestation' => $isAttestation,
                            ])
                            ->attach($pdf, $filename, 'application/pdf');
                        $this->mailer->send($mail);
                        $this->addFlash('success', sprintf(
                            '%s envoyée à %s.', $isAttestation ? 'Attestation famille' : 'Facture famille', $primary->getEmail(),
                        ));
                        return $this->redirectToRoute('admin_invoice_family_pick');
                    }
                } else {
                    return new Response($pdf, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="'.$filename.'"',
                    ]);
                }
            }
        }

        // GET : pré-remplit les montants suggérés depuis la grille tarifaire.
        $suggestions = [];
        foreach ($candidates as $u) {
            $cents = $this->invoiceService->suggestedAmountCents($u, $season);
            $suggestions[$u->getId()] = $cents !== null ? number_format($cents / 100, 2, ',', '') : '';
        }

        return $this->render('admin/family_invoice_build.html.twig', [
            'primary' => $primary,
            'season' => $season,
            'candidates' => $candidates,
            'suggestions' => $suggestions,
            // Options pour le dropdown mode de paiement (label => value).
            'paymentTypes' => array_combine(
                array_map(fn (PaymentType $p) => $p->label(), PaymentType::cases()),
                array_map(fn (PaymentType $p) => $p->value, PaymentType::cases()),
            ),
            // POST vers l'URL courante (préserve les params EA déjà en URL)
            'formAction' => $request->getRequestUri(),
            // Retour vers pick — layout standalone ne dépend pas du
            // contexte EA, donc URL Symfony native OK.
            'backUrl' => $this->generateUrl('admin_invoice_family_pick'),
        ]);
    }
}
