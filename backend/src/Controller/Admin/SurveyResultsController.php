<?php

namespace App\Controller\Admin;

use App\Entity\Survey;
use App\Entity\SurveyResponse;
use App\Enum\SurveyQuestionType;
use App\Repository\SurveyRepository;
use App\Repository\SurveyResponseRepository;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vue « résultats » d'un sondage, sous forme de matrice :
 *  - une ligne par adhérent ayant répondu ;
 *  - un groupe de colonnes par section (= question) ;
 *  - choix unique / multiple : une colonne par option, case cochée ou non,
 *    avec le comptage global de chaque colonne en pied de tableau ;
 *  - texte : une seule colonne avec la réponse saisie (pas de comptage).
 */
#[IsGranted('ROLE_EDITEUR')]
class SurveyResultsController extends AbstractController
{
    public function __construct(
        private readonly SurveyRepository $surveys,
        private readonly SurveyResponseRepository $responses,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    /** Voir doc dans EventAttendanceReportController::adminRoute(). */
    private function adminRoute(string $routeName, array $params = []): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setRoute($routeName, $params)
            ->generateUrl();
    }

    #[Route('/admin/survey/{id}/results', name: 'admin_survey_results', requirements: ['id' => '\d+'])]
    public function results(int $id): Response
    {
        $survey = $this->surveys->find($id);
        if ($survey === null) {
            throw $this->createNotFoundException('Sondage introuvable.');
        }
        $responses = $this->responses->findBySurveyWithUser($survey);

        return $this->render('admin/survey_results.html.twig', [
            'survey' => $survey,
            'responseCount' => count($responses),
            'matrix' => $this->buildMatrix($survey, $responses),
            'csvUrl' => $this->adminRoute('admin_survey_results_csv', ['id' => $survey->getId()]),
        ]);
    }

    #[Route('/admin/survey/{id}/results.csv', name: 'admin_survey_results_csv', requirements: ['id' => '\d+'])]
    public function exportCsv(int $id): StreamedResponse
    {
        $survey = $this->surveys->find($id);
        if ($survey === null) {
            throw $this->createNotFoundException('Sondage introuvable.');
        }
        $responses = $this->responses->findBySurveyWithUser($survey);
        $sections = $survey->getSections() ?? [];

        $response = new StreamedResponse(function () use ($survey, $sections, $responses): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Sondage', $survey->getTitle()], ';');
            fputcsv($out, ['Répondants', count($responses)], ';');
            fputcsv($out, [], ';');

            $header = ['Adhérent', 'N° licence', 'Email', 'Répondu le'];
            foreach ($sections as $q) {
                $header[] = $q['label'] ?? $q['id'] ?? '?';
            }
            fputcsv($out, $header, ';');

            foreach ($responses as $r) {
                $u = $r->getUser();
                $row = [
                    $u->getFullName(),
                    $u->getNumLicence(),
                    $u->getEmail(),
                    ($r->getUpdatedAt() ?? $r->getSubmittedAt())->format('d/m/Y H:i'),
                ];
                foreach ($sections as $q) {
                    $id = $q['id'] ?? null;
                    $v = $id !== null ? ($r->getAnswers()[$id] ?? '') : '';
                    if (is_array($v)) $v = implode(' · ', $v);
                    $row[] = (string) $v;
                }
                fputcsv($out, $row, ';');
            }
            fclose($out);
        });

        $slug = preg_replace('/[^a-z0-9]+/i', '-', $survey->getTitle()) ?: 'sondage';
        $filename = sprintf('sondage-%s-%s.csv', strtolower(trim($slug, '-')), date('Ymd-Hi'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        return $response;
    }

    /**
     * Construit la matrice des résultats.
     *
     * groups : une entrée par section — { label, typeLabel, isText, options[] }.
     *          Les sections de choix sans option sont ignorées (rien à cocher).
     * rows   : une entrée par répondant, triée par nom — { fullName, numLicence,
     *          email, at, cells[] } où cells[i] correspond à groups[i] :
     *          texte → string|null ; choix → list<bool> (une case par option).
     * totals : totals[i] = list<int> — nombre de cases cochées par option
     *          (vide pour une section texte).
     *
     * Une réponse dont la valeur n'est plus dans les options du sondage (schéma
     * modifié après coup) ne coche aucune case ; elle reste visible dans l'export CSV.
     *
     * @param list<SurveyResponse> $responses
     * @return array{groups: list<array<string, mixed>>, rows: list<array<string, mixed>>, totals: list<list<int>>}
     */
    private function buildMatrix(Survey $survey, array $responses): array
    {
        $groups = [];
        foreach ($survey->getSections() ?? [] as $q) {
            $qid = $q['id'] ?? null;
            $type = SurveyQuestionType::tryFrom((string) ($q['type'] ?? ''));
            if (!is_string($qid) || $type === null) {
                continue;
            }
            $isText = $type === SurveyQuestionType::ShortText || $type === SurveyQuestionType::LongText;
            $options = $isText ? [] : array_values(array_filter((array) ($q['options'] ?? []), 'is_string'));
            if (!$isText && $options === []) {
                continue;
            }
            $groups[] = [
                'id' => $qid,
                'label' => $q['label'] ?? $qid,
                'typeLabel' => $type->label(),
                'isText' => $isText,
                'options' => $options,
            ];
        }

        $totals = array_map(fn (array $g) => array_fill(0, count($g['options']), 0), $groups);

        $rows = [];
        foreach ($responses as $r) {
            $answers = $r->getAnswers();
            $cells = [];
            foreach ($groups as $gi => $g) {
                $value = $answers[$g['id']] ?? null;
                if ($g['isText']) {
                    $cells[] = is_string($value) && trim($value) !== '' ? $value : null;
                    continue;
                }
                $selected = is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : []);
                $checks = [];
                foreach ($g['options'] as $oi => $option) {
                    $checked = in_array($option, $selected, true);
                    $checks[] = $checked;
                    if ($checked) {
                        $totals[$gi][$oi]++;
                    }
                }
                $cells[] = $checks;
            }

            $u = $r->getUser();
            $rows[] = [
                'sortKey' => trim($u->getNom().' '.$u->getPrenom()),
                'fullName' => $u->getFullName(),
                'numLicence' => $u->getNumLicence(),
                'email' => $u->getEmail(),
                'at' => $r->getUpdatedAt() ?? $r->getSubmittedAt(),
                'cells' => $cells,
            ];
        }

        $collator = class_exists(\Collator::class) ? new \Collator('fr_FR') : null;
        usort($rows, fn (array $a, array $b) => $collator !== null
            ? $collator->compare($a['sortKey'], $b['sortKey'])
            : strcasecmp($a['sortKey'], $b['sortKey']));

        return ['groups' => $groups, 'rows' => $rows, 'totals' => $totals];
    }
}
