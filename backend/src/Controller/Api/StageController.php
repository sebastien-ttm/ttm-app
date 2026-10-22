<?php

namespace App\Controller\Api;

use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\Stage;
use App\Entity\Survey;
use App\Entity\User;
use App\Repository\StageRepository;
use App\Repository\SurveyDismissalRepository;
use App\Repository\SurveyResponseRepository;
use App\Service\Context\ContextContentSerializer;
use App\Service\Training\TrainingRegistrationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Stages du club (créés depuis le backend) : chaque participant retrouve dans l'appli une vue dédiée avec les
 * informations pratiques et TOUS les éléments propres au stage — événements, articles, sondages, prises de temps et
 * entraînements. Ces éléments ne figurent nulle part ailleurs dans l'appli. Un stage n'est jamais visible d'un
 * adhérent qui n'y participe pas (404), ni tant qu'il est en brouillon.
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/stages')]
class StageController extends AbstractController
{
    public function __construct(
        private readonly StageRepository $stages,
        private readonly ContextContentSerializer $content,
        private readonly TrainingRegistrationService $trainingRegistrations,
        private readonly SurveyResponseRepository $surveyResponses,
        private readonly SurveyDismissalRepository $surveyDismissals,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Mes stages : à venir, en cours ou terminés depuis peu (de quoi alimenter l'accueil). */
    #[Route('/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        return new JsonResponse([
            'data' => array_map(fn (Stage $s) => $this->summary($s), $this->stages->findCurrentForParticipant($viewer)),
        ]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(Stage $stage): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$stage->isOpenTo($viewer)) {
            throw $this->createNotFoundException();
        }

        // Entraînements occasionnels du stage au format de la semaine d'entraînement (avec leur inscription
        // éventuelle : l'appli ouvre directement leur écran détail). Information annexe : une erreur ne doit pas
        // empêcher de lire le reste du stage.
        try {
            $trainingSlots = $this->trainingRegistrations->decoratedSlots($stage->getTrainingSlots(), $viewer);
        } catch (\Throwable $e) {
            $this->logger->warning('Entraînements du stage indisponibles', ['stageId' => $stage->getId(), 'error' => $e->getMessage()]);
            $trainingSlots = [];
        }

        return new JsonResponse($this->summary($stage) + [
            'description' => $stage->getDescription(),
            'trainingSlots' => $trainingSlots,
            'events' => $this->content->events($stage->getEvents(), $viewer, applyAudience: false),
            'surveys' => $this->surveys($stage, $viewer),
            'perfTests' => array_map(
                fn (PerfTestSession $s) => $this->perfTest($s, $viewer),
                $stage->getPerfTestSessions()->toArray(),
            ),
            'articles' => $this->content->articles($stage->getArticles(), $viewer),
            'participants' => $this->content->participants($stage->getParticipants()),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Stage $stage): array
    {
        return [
            'id' => $stage->getId(),
            'title' => $stage->getTitle(),
            'icon' => $stage->getIcon(),
            'location' => $stage->getLocation(),
            'startsOn' => $stage->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $stage->getEndsOn()?->format('Y-m-d'),
            'participantCount' => $stage->getParticipants()->count(),
        ];
    }

    /**
     * Sondages publiés du stage (clos compris, repérés par `isClosed`), au format de la liste des sondages ouverts :
     * l'appli ouvre ensuite l'écran de réponse habituel.
     *
     * @return list<array<string, mixed>>
     */
    private function surveys(Stage $stage, User $viewer): array
    {
        $rows = array_values(array_filter(
            $stage->getSurveys()->toArray(),
            static fn (Survey $s) => $s->isPublished(),
        ));
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (Survey $s) => (int) $s->getId(), $rows);
        $answered = $this->surveyResponses->findAnsweredSurveyIds($viewer, $ids);
        $dismissed = $this->surveyDismissals->findDismissedSurveyIds($viewer, $ids);
        $counts = $this->surveyResponses->countBySurveyIds(array_keys($answered));

        return array_map(fn (Survey $s) => [
            'id' => $s->getId(),
            'title' => $s->getTitle(),
            'description' => $s->getDescription(),
            'publishedAt' => $s->getPublishedAt()?->format(\DATE_ATOM),
            'closesAt' => $s->getClosesAt()?->format(\DATE_ATOM),
            'isClosed' => $s->isClosed(),
            'sectionCount' => count($s->getSections() ?? []),
            'answered' => isset($answered[$s->getId()]),
            'dismissed' => !isset($answered[$s->getId()]) && isset($dismissed[$s->getId()]),
            // Nombre total de réponses : visible uniquement par ceux qui ont répondu.
            'responseCount' => isset($answered[$s->getId()]) ? ($counts[$s->getId()] ?? null) : null,
        ], $rows);
    }

    /**
     * Prise de temps du stage : épreuve, période et temps classés du plus rapide au plus lent (ex æquo = même rang),
     * comme dans les tests chronométrés généraux.
     *
     * @return array<string, mixed>
     */
    private function perfTest(PerfTestSession $session, User $viewer): array
    {
        $results = $session->getResults()->toArray();
        usort($results, static fn (PerfTestResult $a, PerfTestResult $b) => $a->getTimeSeconds() <=> $b->getTimeSeconds()
            ?: strcmp($a->getSortName(), $b->getSortName()));

        $rows = [];
        $rank = 0;
        $previous = null;
        foreach ($results as $i => $r) {
            if ($r->getTimeSeconds() !== $previous) {
                $rank = $i + 1;
                $previous = $r->getTimeSeconds();
            }
            $rows[] = [
                'rank' => $rank,
                'fullName' => $r->getDisplayName(),
                'timeSeconds' => $r->getTimeSeconds(),
                'time' => PerfTestResult::format($r->getTimeSeconds()),
                'mine' => $r->getUser() !== null && $r->getUser()->getId() === $viewer->getId(),
            ];
        }

        $test = $session->getTest();
        $pool = $session->getPoolLength();

        return [
            'id' => $session->getId(),
            'test' => $test->value,
            'label' => $test->label().($pool !== null ? ' — bassin '.$pool.' m' : ''),
            'icon' => $test->icon(),
            'poolLength' => $pool,
            // Période de la prise de temps ; endDate null = une seule journée.
            'date' => $session->getDate()->format('Y-m-d'),
            'endDate' => $session->isSingleDay() ? null : $session->getPeriodEnd()->format('Y-m-d'),
            'notes' => $session->getNotes(),
            'participants' => count($rows),
            'results' => $rows,
        ];
    }
}
