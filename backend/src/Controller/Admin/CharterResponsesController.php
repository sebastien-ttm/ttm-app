<?php

namespace App\Controller\Admin;

use App\Entity\CharterAcceptance;
use App\Entity\ClubCharter;
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
        private readonly ClubCharterRepository $charters,
    ) {
    }

    #[Route('/admin/charter/responses', name: 'admin_charter_responses')]
    public function index(): Response
    {
        $charter = $this->charters->findCurrent();

        $fields = [];
        $rows = [];
        if ($charter !== null && $charter->hasForm()) {
            $fields = $charter->getFields() ?? [];
            foreach ($charter->getAcceptances() as $acc) {
                $rows[] = [
                    'user' => $acc->getUser(),
                    'acceptedAt' => $acc->getAcceptedAt(),
                    'answers' => $acc->getAnswers() ?? [],
                ];
            }
            // tri : acceptations les plus récentes en premier
            usort($rows, fn ($a, $b) => $b['acceptedAt'] <=> $a['acceptedAt']);
        }

        return $this->render('admin/charter_responses.html.twig', [
            'charter' => $charter,
            'fields' => $fields,
            'rows' => $rows,
        ]);
    }

    #[Route('/admin/charter/responses.csv', name: 'admin_charter_responses_csv')]
    public function exportCsv(): StreamedResponse
    {
        $charter = $this->charters->findCurrent();

        $response = new StreamedResponse(function () use ($charter): void {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel
            fwrite($out, "\xEF\xBB\xBF");

            if ($charter === null || !$charter->hasForm()) {
                fputcsv($out, ['Aucune charte avec formulaire active.'], ';');
                fclose($out);
                return;
            }

            $fields = $charter->getFields() ?? [];
            $header = ['N° licence', 'Nom', 'Prénom', 'E-mail', 'Acceptée le'];
            foreach ($fields as $f) {
                $header[] = $f['label'] ?? $f['id'] ?? '?';
            }
            fputcsv($out, $header, ';');

            /** @var CharterAcceptance $acc */
            foreach ($charter->getAcceptances() as $acc) {
                $u = $acc->getUser();
                $answers = $acc->getAnswers() ?? [];
                $line = [
                    $u->getNumLicence(),
                    $u->getNom(),
                    $u->getPrenom(),
                    $u->getEmail(),
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

        $version = $charter?->getVersion() ?? 'export';
        $filename = sprintf('charte-%s-reponses-%s.csv', $this->slug($version), date('Ymd-Hi'));

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
        return trim(strtolower($s), '-') ?: 'export';
    }
}
