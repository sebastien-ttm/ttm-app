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
 * Vue « résultats » d'un sondage :
 *  - texte : liste des réponses (avec auteur)
 *  - choix unique : histogramme (count par option)
 *  - choix multiple : idem, chaque option comptée indépendamment
 */
#[IsGranted('ROLE_ADMIN')]
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
        $aggregate = $this->aggregate($survey, $responses);

        return $this->render('admin/survey_results.html.twig', [
            'survey' => $survey,
            'responseCount' => count($responses),
            'sections' => $aggregate,
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
     * Agrège les réponses par section pour l'affichage :
     *  - text/textarea → { type, question, texts: [{user, value, updatedAt}], count }
     *  - single_choice / multi_choice → { type, question, tallies: [{option, count, percent}], count }
     *
     * @param list<SurveyResponse> $responses
     * @return list<array<string, mixed>>
     */
    private function aggregate(Survey $survey, array $responses): array
    {
        $sections = $survey->getSections() ?? [];
        $out = [];
        foreach ($sections as $q) {
            $id = $q['id'] ?? null;
            $type = SurveyQuestionType::tryFrom((string) ($q['type'] ?? ''));
            if (!is_string($id) || $type === null) continue;

            $entry = [
                'id' => $id,
                'label' => $q['label'] ?? $id,
                'type' => $type->value,
                'typeLabel' => $type->label(),
                'help' => $q['help'] ?? null,
            ];

            if ($type === SurveyQuestionType::ShortText || $type === SurveyQuestionType::LongText) {
                $texts = [];
                foreach ($responses as $r) {
                    $v = $r->getAnswers()[$id] ?? null;
                    if (!is_string($v) || trim($v) === '') continue;
                    $texts[] = [
                        'user' => $r->getUser()->getFullName(),
                        'value' => $v,
                        'updatedAt' => $r->getUpdatedAt() ?? $r->getSubmittedAt(),
                    ];
                }
                $entry['texts'] = $texts;
                $entry['count'] = count($texts);
            } else {
                $opts = array_values(array_filter((array) ($q['options'] ?? []), 'is_string'));
                $tally = array_fill_keys($opts, 0);
                $totalVotes = 0;
                foreach ($responses as $r) {
                    $v = $r->getAnswers()[$id] ?? null;
                    if ($v === null || $v === '') continue;
                    if ($type === SurveyQuestionType::MultiChoice) {
                        if (!is_array($v)) continue;
                        foreach ($v as $vv) {
                            if (isset($tally[$vv])) { $tally[$vv]++; $totalVotes++; }
                        }
                    } else {
                        if (is_string($v) && isset($tally[$v])) { $tally[$v]++; $totalVotes++; }
                    }
                }
                $tallies = [];
                $baseline = $type === SurveyQuestionType::MultiChoice ? count($responses) : $totalVotes;
                foreach ($tally as $opt => $n) {
                    $tallies[] = [
                        'option' => $opt,
                        'count' => $n,
                        'percent' => $baseline > 0 ? (int) round(100 * $n / $baseline) : 0,
                    ];
                }
                $entry['tallies'] = $tallies;
                $entry['count'] = $totalVotes;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
