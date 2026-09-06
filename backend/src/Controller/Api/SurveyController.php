<?php

namespace App\Controller\Api;

use App\Entity\Survey;
use App\Entity\SurveyResponse;
use App\Entity\User;
use App\Repository\SurveyRepository;
use App\Repository\SurveyResponseRepository;
use App\Service\Survey\SurveySchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sondages accessibles depuis l'onglet Contact du mobile.
 *
 * L'user peut voir la liste des sondages ouverts qui le ciblent
 * (audience), consulter le détail d'un sondage (avec sa réponse
 * existante s'il en a une), et soumettre/modifier sa réponse tant
 * que le sondage est ouvert.
 */
#[IsGranted('ROLE_USER')]
class SurveyController extends AbstractController
{
    public function __construct(
        private readonly SurveyRepository $surveys,
        private readonly SurveyResponseRepository $responses,
        private readonly SurveySchemaValidator $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/me/surveys', methods: ['GET'])]
    public function listOpen(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $rows = $this->surveys->findOpenFor($user);
        $ids = array_map(fn (Survey $s) => (int) $s->getId(), $rows);
        $answered = $this->responses->findAnsweredSurveyIds($user, $ids);

        return new JsonResponse([
            'data' => array_map(fn (Survey $s) => $this->serializeSummary($s, isset($answered[$s->getId()])), $rows),
        ]);
    }

    #[Route('/api/me/surveys/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $survey = $this->surveys->find($id);
        if ($survey === null || !$survey->isPublished()) {
            return new JsonResponse(['error' => 'Sondage introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $mine = $this->responses->findOneByUserAndSurvey($user, $survey);
        return new JsonResponse($this->serializeFull($survey, $mine));
    }

    #[Route('/api/me/surveys/{id}/response', methods: ['POST', 'PUT'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $survey = $this->surveys->find($id);
        if ($survey === null || !$survey->isPublished()) {
            return new JsonResponse(['error' => 'Sondage introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($survey->isClosed()) {
            return new JsonResponse(['error' => 'Ce sondage est fermé.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $rawAnswers = is_array($payload) ? ($payload['answers'] ?? null) : null;

        $errors = $this->validator->validateAnswers($survey->getSections(), $rawAnswers);
        if ($errors !== []) {
            return new JsonResponse(['error' => 'Formulaire invalide.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $clean = $this->validator->normalize($survey->getSections(), is_array($rawAnswers) ? $rawAnswers : []);

        $existing = $this->responses->findOneByUserAndSurvey($user, $survey);
        if ($existing !== null) {
            $existing->setAnswers($clean);
        } else {
            $existing = new SurveyResponse($user, $survey);
            $existing->setAnswers($clean);
            $this->em->persist($existing);
        }
        $this->em->flush();

        return new JsonResponse($this->serializeFull($survey, $existing));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(Survey $s, bool $answered): array
    {
        return [
            'id' => $s->getId(),
            'title' => $s->getTitle(),
            'description' => $s->getDescription(),
            'publishedAt' => $s->getPublishedAt()?->format(\DATE_ATOM),
            'closesAt' => $s->getClosesAt()?->format(\DATE_ATOM),
            'sectionCount' => count($s->getSections() ?? []),
            'answered' => $answered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFull(Survey $s, ?SurveyResponse $mine): array
    {
        return [
            'id' => $s->getId(),
            'title' => $s->getTitle(),
            'description' => $s->getDescription(),
            'publishedAt' => $s->getPublishedAt()?->format(\DATE_ATOM),
            'closesAt' => $s->getClosesAt()?->format(\DATE_ATOM),
            'isClosed' => $s->isClosed(),
            'sections' => $s->getSections() ?? [],
            'myResponse' => $mine === null ? null : [
                'answers' => $mine->getAnswers(),
                'submittedAt' => $mine->getSubmittedAt()->format(\DATE_ATOM),
                'updatedAt' => $mine->getUpdatedAt()?->format(\DATE_ATOM),
            ],
        ];
    }
}
