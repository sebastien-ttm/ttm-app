<?php

namespace App\Controller\Admin;

use App\Entity\CharterAcceptance;
use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CharterResponsesController extends AbstractController
{
    public function __construct(
        private readonly CharterAcceptanceRepository $acceptances,
        private readonly ClubCharterRepository $charters,
    ) {
    }

    #[Route('/admin/charter/responses', name: 'admin_charter_responses')]
    public function index(): Response
    {
        // Les engagements du tunnel courant (message de bienvenue le plus
        // récent). Les acceptations plus anciennes ont pu répondre à un
        // schéma différent — on colonne sur le schéma courant (best effort).
        $currentCharter = $this->charters->findCurrent();
        $fields = $currentCharter?->getFields() ?? [];

        $rows = [];
        if (count($fields) > 0) {
            $all = $this->acceptances->createQueryBuilder('a')
                ->leftJoin('a.user', 'u')->addSelect('u')
                ->leftJoin('a.charter', 'c')->addSelect('c')
                ->orderBy('a.acceptedAt', 'DESC')
                ->getQuery()->getResult();
            /** @var CharterAcceptance $acc */
            foreach ($all as $acc) {
                $rows[] = [
                    'user' => $acc->getUser(),
                    'acceptedAt' => $acc->getAcceptedAt(),
                    'charterTitle' => $acc->getCharter()->getTitle(),
                    'answers' => $acc->getAnswers() ?? [],
                ];
            }
        }

        return $this->render('admin/charter_responses.html.twig', [
            'hasEngagements' => count($fields) > 0,
            'fields' => $fields,
            'rows' => $rows,
        ]);
    }

    #[Route('/admin/charter/responses.csv', name: 'admin_charter_responses_csv')]
    public function exportCsv(): StreamedResponse
    {
        $currentCharter = $this->charters->findCurrent();
        $fields = $currentCharter?->getFields() ?? [];

        $response = new StreamedResponse(function () use ($fields): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            if (count($fields) === 0) {
                fputcsv($out, ['Aucun engagement défini dans le message de bienvenue courant.'], ';');
                fclose($out);
                return;
            }

            $header = ['N° licence', 'Nom', 'Prénom', 'E-mail', 'Formulaire signé', 'Acceptée le'];
            foreach ($fields as $f) {
                $header[] = $f['label'] ?? $f['id'] ?? '?';
            }
            fputcsv($out, $header, ';');

            $all = $this->acceptances->createQueryBuilder('a')
                ->leftJoin('a.user', 'u')->addSelect('u')
                ->leftJoin('a.charter', 'c')->addSelect('c')
                ->orderBy('a.acceptedAt', 'DESC')
                ->getQuery()->getResult();
            /** @var CharterAcceptance $acc */
            foreach ($all as $acc) {
                $u = $acc->getUser();
                $answers = $acc->getAnswers() ?? [];
                $line = [
                    $u->getNumLicence(),
                    $u->getNom(),
                    $u->getPrenom(),
                    $u->getEmail(),
                    $acc->getCharter()->getTitle(),
                    $acc->getAcceptedAt()->format('Y-m-d H:i'),
                ];
                foreach ($fields as $f) {
                    $id = $f['id'] ?? null;
                    $v = $id !== null ? ($answers[$id] ?? '') : '';
                    if (is_array($v)) {
                        $v = implode(', ', array_map('strval', $v));
                    } elseif (is_bool($v)) {
                        $v = $v ? 'Oui' : 'Non';
                    }
                    $line[] = (string) $v;
                }
                fputcsv($out, $line, ';');
            }
            fclose($out);
        });

        $filename = sprintf('charte-reponses-%s.csv', date('Ymd-Hi'));

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;
    }
}
