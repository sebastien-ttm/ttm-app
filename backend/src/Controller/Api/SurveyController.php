<?php

namespace App\Controller\Api;

use App\Entity\Survey;
use App\Entity\SurveyDismissal;
use App\Entity\SurveyResponse;
use App\Entity\User;
use App\Repository\SurveyDismissalRepository;
use App\Repository\SurveyRepository;
use App\Repository\SurveyResponseRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\MemberGroup\MemberGroupService;
use App\Service\Survey\SurveySchemaValidator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        private readonly SurveyDismissalRepository $dismissals,
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
        $dismissed = $this->dismissals->findDismissedSurveyIds($user, $ids);
        $counts = $this->responses->countBySurveyIds(array_keys($answered));

        return new JsonResponse([
            'data' => array_map(
                fn (Survey $s) => $this->serializeSummary(
                    $s,
                    isset($answered[$s->getId()]),
                    isset($dismissed[$s->getId()]),
                    $counts[$s->getId()] ?? null,
                ),
                $rows,
            ),
        ]);
    }

    /**
     * Compteur agrégé pour le badge « sondages non répondus » (titre
     * « Sondages en cours » + onglet Contact). Même logique que
     * listOpen(), réduite à un chiffre pour éviter de recharger la
     * liste complète à chaque poll. Les sondages écartés (« pas
     * concerné ») ne comptent pas.
     */
    #[Route('/api/me/surveys/unanswered-count', methods: ['GET'])]
    public function unansweredCount(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $rows = $this->surveys->findOpenFor($user);
        $ids = array_map(fn (Survey $s) => (int) $s->getId(), $rows);
        $answered = $this->responses->findAnsweredSurveyIds($user, $ids);
        $dismissed = $this->dismissals->findDismissedSurveyIds($user, $ids);

        $pending = array_filter($ids, fn (int $id) => !isset($answered[$id]) && !isset($dismissed[$id]));

        return new JsonResponse(['count' => count($pending)]);
    }

    /**
     * « Pas concerné » : coche le sondage sans y répondre (il sort du
     * compteur). Idempotent. Refusé si le user a déjà répondu — la
     * réponse fait déjà foi.
     */
    #[Route('/api/me/surveys/{id}/dismissal', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function dismiss(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $survey = $this->findVisibleOr404($id, $user);

        if ($this->responses->findOneByUserAndSurvey($user, $survey) !== null) {
            return new JsonResponse(['error' => 'Vous avez déjà répondu à ce sondage.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->dismissals->findOneByUserAndSurvey($user, $survey) === null) {
            $this->em->persist(new SurveyDismissal($user, $survey));
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // Double appui : la ligne existe déjà, c'est l'état voulu.
            }
        }

        return new JsonResponse(['dismissed' => true]);
    }

    /** Annule le « pas concerné » : le sondage redevient à traiter. Idempotent. */
    #[Route('/api/me/surveys/{id}/dismissal', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function undismiss(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $survey = $this->findVisibleOr404($id, $user);

        $existing = $this->dismissals->findOneByUserAndSurvey($user, $survey);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        return new JsonResponse(['dismissed' => false]);
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

        // Répondre l'emporte sur « pas concerné » : on retire la coche
        // manuelle, la réponse suffit à marquer le sondage comme traité.
        $dismissal = $this->dismissals->findOneByUserAndSurvey($user, $survey);
        if ($dismissal !== null) {
            $this->em->remove($dismissal);
        }

        // Rattachement automatique aux groupes : chaque question du
        // schéma qui déclare un `groupTarget` peut ajouter (ou retirer)
        // le user d'un groupe selon que la réponse matche le trigger.
        $this->memberGroups->syncSurveyGroupTargets($survey->getSections() ?? [], $clean, $user);

        $this->em->flush();

        return new JsonResponse($this->serializeFull($survey, $existing));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(Survey $s, bool $answered, bool $dismissed, ?int $responseCount = null): array
    {
        return [
            'id' => $s->getId(),
            'title' => $s->getTitle(),
            'description' => $s->getDescription(),
            'publishedAt' => $s->getPublishedAt()?->format(\DATE_ATOM),
            'closesAt' => $s->getClosesAt()?->format(\DATE_ATOM),
            'sectionCount' => count($s->getSections() ?? []),
            'answered' => $answered,
            // Coche « pas concerné » posée par l'adhérent (sans réponse).
            'dismissed' => !$answered && $dismissed,
            // Nombre total de réponses : visible uniquement par ceux qui ont répondu.
            'responseCount' => $answered ? $responseCount : null,
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
            // Nombre total de réponses : visible uniquement par ceux qui ont répondu.
            'responseCount' => $mine === null ? null : $this->responses->countForSurvey($s),
            'sections' => $s->getSections() ?? [],
            'myResponse' => $mine === null ? null : [
                'answers' => $mine->getAnswers(),
                'submittedAt' => $mine->getSubmittedAt()->format(\DATE_ATOM),
                'updatedAt' => $mine->getUpdatedAt()?->format(\DATE_ATOM),
            ],
        ];
    }
}
