<?php

namespace App\Controller\Api;

use App\Entity\Survey;
use App\Entity\SurveyResponse;
use App\Entity\User;
use App\Entity\MemberGroupMember;
use App\Repository\SurveyRepository;
use App\Repository\SurveyResponseRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\MemberGroup\MemberGroupService;
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
        private readonly AudienceFilter $audienceFilter,
        private readonly MemberGroupService $memberGroups,
    ) {
    }

    /**
     * Filet audience pour les endpoints unitaires (get / submit) :
     * un deep-link direct ne doit pas contourner le filtrage appliqué
     * à la liste. Retourne le sondage s'il est publié ET visible pour
     * le viewer, sinon 404 (on ne révèle pas l'existence).
     */
    private function findVisibleOr404(int $id, ?User $viewer): Survey
    {
        $survey = $this->surveys->find($id);
        if ($survey === null
            || !$survey->isPublished()
            || !$this->audienceFilter->isVisible($survey->getAudience(), $viewer)
        ) {
            throw $this->createNotFoundException();
        }
        return $survey;
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
        $survey = $this->findVisibleOr404($id, $user);
        $mine = $this->responses->findOneByUserAndSurvey($user, $survey);
        return new JsonResponse($this->serializeFull($survey, $mine));
    }

    #[Route('/api/me/surveys/{id}/response', methods: ['POST', 'PUT'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $survey = $this->findVisibleOr404($id, $user);
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

        // Rattachement automatique aux groupes : chaque question du
        // schéma qui déclare un `groupTarget` peut ajouter (ou retirer)
        // le user d'un groupe selon que la réponse matche le trigger.
        $this->syncGroupTargets($survey->getSections() ?? [], $clean, $user);

        $this->em->flush();

        return new JsonResponse($this->serializeFull($survey, $existing));
    }

    /**
     * Parcourt le schéma pour toutes les questions déclarant un bloc
     * `groupTarget` ; si la valeur soumise matche le trigger, ajoute
     * le user au groupe (créé au besoin, saison courante). Sinon, on
     * le retire — un membre qui change son vote « Oui → Non » sort du
     * groupe. Aucun flush ici : l'appelant flush après.
     *
     * @param list<array<string, mixed>> $sections
     * @param array<string, mixed>       $answers
     */
    private function syncGroupTargets(array $sections, array $answers, User $user): void
    {
        foreach ($sections as $q) {
            $target = $q['groupTarget'] ?? null;
            if (!is_array($target)) continue;
            $qid = $q['id'] ?? null;
            $name = isset($target['name']) ? trim((string) $target['name']) : '';
            $trigger = isset($target['trigger']) ? (string) $target['trigger'] : '';
            if (!is_string($qid) || $name === '' || $trigger === '') continue;

            $answer = $answers[$qid] ?? null;
            $matches = false;
            if (is_string($answer)) {
                $matches = $answer === $trigger;
            } elseif (is_array($answer)) {
                $matches = in_array($trigger, $answer, true);
            }

            $group = $this->memberGroups->ensureGroupForSurvey($name, (string) ($q['label'] ?? $qid));
            if ($matches) {
                $this->memberGroups->addMember($group, $user, MemberGroupMember::SOURCE_SURVEY_ANSWER);
            } else {
                $this->memberGroups->removeMember($group, $user);
            }
        }
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
