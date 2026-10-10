<?php

namespace App\Controller\Api;

use App\Entity\PerfTestDeclaration;
use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Enum\PerfTest;
use App\Repository\PerfTestDeclarationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Temps déclarés par les adhérents (prise de temps individuelle) : l'adhérent
 * déclare son temps depuis l'onglet de l'épreuve, les entraîneurs
 * l'acceptent ou le refusent depuis le backend (voir
 * PerfTestDeclarationAdminController). Réservé aux adhérents licenciés.
 */
#[IsGranted('ROLE_USER')]
class PerfTestDeclarationController extends AbstractController
{
    /** Anti-spam : demandes en attente simultanées par adhérent. */
    private const MAX_PENDING = 5;
    /** Ancienneté maximale de la prise de temps déclarée (jours). */
    private const MAX_AGE_DAYS = 400;
    private const MAX_COMMENT_LENGTH = 500;

    public function __construct(
        private readonly PerfTestDeclarationRepository $declarations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Mes demandes (toutes épreuves), la plus récente d'abord. */
    #[Route('/api/perf-tests/declarations', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessLicensed($viewer);

        return new JsonResponse([
            'data' => array_map(
                fn (PerfTestDeclaration $d) => $this->serialize($d),
                $this->declarations->findByUser($viewer),
            ),
        ]);
    }

    /**
     * Body : { test, poolLength?, date: 'YYYY-MM-DD', time: '5:42', comment? }
     */
    #[Route('/api/perf-tests/declarations', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessLicensed($viewer);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $test = PerfTest::tryFrom((string) ($payload['test'] ?? ''));
        if ($test === null) {
            return new JsonResponse(['error' => 'Épreuve inconnue.'], Response::HTTP_BAD_REQUEST);
        }
        $pool = null;
        if ($test->needsPoolLength()) {
            $pool = (int) ($payload['poolLength'] ?? 0);
            if (!in_array($pool, PerfTestSession::POOL_LENGTHS, true)) {
                return new JsonResponse(['error' => 'Choisissez la longueur du bassin (25 ou 50 m).'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $rawDate = (string) ($payload['date'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
        if ($date === false || $date->format('Y-m-d') !== $rawDate) {
            return new JsonResponse(['error' => 'Date invalide (JJ/MM/AAAA).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $today = new \DateTimeImmutable('today');
        if ($date > $today) {
            return new JsonResponse(['error' => 'La date ne peut pas être dans le futur.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($date < $today->modify('-'.self::MAX_AGE_DAYS.' days')) {
            return new JsonResponse(
                ['error' => 'Date trop ancienne : pour un temps d\'une ancienne saison, contactez les entraîneurs.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $seconds = PerfTestResult::parse((string) ($payload['time'] ?? ''));
        if ($seconds === null) {
            return new JsonResponse(
                ['error' => 'Temps illisible : saisissez par ex. 5:42 (min:s) ou 1:02:15 (h:min:s).'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        [$min, $max] = $test->plausibleSeconds();
        if ($seconds < $min || $seconds > $max) {
            return new JsonResponse(
                ['error' => sprintf('Temps invraisemblable (%s) pour cette épreuve : vérifiez le format (min:s).', PerfTestResult::format($seconds))],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $comment = trim((string) ($payload['comment'] ?? ''));
        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            return new JsonResponse(
                ['error' => sprintf('Commentaire trop long (%d caractères max).', self::MAX_COMMENT_LENGTH)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($this->declarations->findDuplicate($viewer, $test, $pool, $date, $seconds) !== null) {
            return new JsonResponse(['error' => 'Vous avez déjà déclaré ce temps.'], Response::HTTP_CONFLICT);
        }
        if ($this->declarations->countPendingByUser($viewer) >= self::MAX_PENDING) {
            return new JsonResponse(
                ['error' => sprintf('Vous avez déjà %d demandes en attente : patientez le temps qu\'elles soient traitées.', self::MAX_PENDING)],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $declaration = new PerfTestDeclaration($viewer, $test, $pool, $date, $seconds, $comment !== '' ? $comment : null);
        $this->em->persist($declaration);
        $this->em->flush();

        return new JsonResponse($this->serialize($declaration), Response::HTTP_CREATED);
    }

    /** Annule MA demande tant qu'elle est en attente (erreur de saisie). */
    #[Route('/api/perf-tests/declarations/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessLicensed($viewer);

        $declaration = $this->declarations->find($id);
        // 404 (et non 403) pour la demande d'un autre : masque son existence.
        if ($declaration === null || $declaration->getUser()->getId() !== $viewer->getId()) {
            throw $this->createNotFoundException();
        }
        if (!$declaration->isPending()) {
            return new JsonResponse(['error' => 'Cette demande a déjà été traitée.'], Response::HTTP_CONFLICT);
        }
        $this->em->remove($declaration);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function denyUnlessLicensed(User $viewer): void
    {
        if (($viewer->getNumLicence() ?? '') === '' || $viewer->isDirigeant()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PerfTestDeclaration $d): array
    {
        return [
            'id' => $d->getId(),
            'test' => $d->getTest()->value,
            'poolLength' => $d->getPoolLength(),
            'label' => $d->getTestLabel(),
            'date' => $d->getPerformedOn()->format('Y-m-d'),
            'timeSeconds' => $d->getTimeSeconds(),
            'time' => $d->getTimeLabel(),
            'comment' => $d->getMemberComment(),
            'status' => $d->getStatus(),
            'decisionNote' => $d->getDecisionNote(),
            'createdAt' => $d->getCreatedAt()->format(\DATE_ATOM),
            'decidedAt' => $d->getDecidedAt()?->format(\DATE_ATOM),
        ];
    }
}
