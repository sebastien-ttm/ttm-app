<?php

namespace App\Controller\Api;

use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Repository\PerfTestResultRepository;
use App\Repository\PerfTestSessionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Saisie des temps des tests chronométrés depuis l'espace « Staff » de l'appli
 * (même logique que la feuille de saisie du backend, PerfTestResultController) :
 * les prises de temps en cours ou récentes, puis un temps par adhérent actif,
 * enregistré immédiatement, avec le rappel du dernier temps et du record.
 *
 * Réservé aux entraîneurs (profil Entraîneur) et aux administrateurs.
 */
#[IsGranted('ROLE_USER')]
class StaffPerfTestController extends AbstractController
{
    use StaffOnlyTrait;
    use StaffLiveStateTrait;

    /** Une prise de temps terminée depuis moins de ce délai reste proposée à la saisie. */
    private const RECENT_DAYS = 45;

    public function __construct(
        private readonly PerfTestSessionRepository $sessions,
        private readonly PerfTestResultRepository $results,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Prises de temps en cours, à venir ou terminées depuis moins de 45 jours (la plus récente d'abord). */
    #[Route('/api/staff/perf-tests', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);

        $today = new \DateTimeImmutable('today');
        $sessions = $this->sessions->findOngoingOrRecent($today->modify('-'.self::RECENT_DAYS.' days'));
        $counts = $this->results->countBySessionIds(array_map(static fn (PerfTestSession $s) => (int) $s->getId(), $sessions));

        return new JsonResponse(['data' => array_map(
            fn (PerfTestSession $s) => $this->sessionPayload($s, $today) + ['resultsCount' => $counts[$s->getId()] ?? 0],
            $sessions,
        )]);
    }

    /** Feuille de saisie : tous les adhérents actifs avec leur temps, leur dernier temps et leur record. */
    #[Route('/api/staff/perf-tests/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sheet(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);
        $session = $this->findSession($id);

        $results = $this->results->findBySessionIndexedByUser($session);
        $history = $this->results->findHistoryBefore($session);

        /** @var array<int, User> $people */
        $people = [];
        foreach ($this->users->findActiveAdherentsForRecap() as $u) {
            $people[$u->getId()] = $u;
        }
        // Adhérent devenu inactif mais déjà chronométré sur cette séance.
        foreach ($results as $userId => $r) {
            $people[$userId] ??= $r->getUser();
        }

        $rows = [];
        foreach ($people as $userId => $u) {
            $h = $history[$userId] ?? null;
            $rows[] = [
                'id' => $userId,
                'nom' => $u->getNom(),
                'prenom' => $u->getPrenom(),
                'categorie' => $u->getCategorieFFTri(),
                'last' => $h ? [
                    'time' => PerfTestResult::format($h['last']->getTimeSeconds()),
                    'date' => $h['last']->getSession()->getDatesLabel(),
                ] : null,
                'best' => $h ? PerfTestResult::format($h['best']->getTimeSeconds()) : null,
            ] + self::state($results[$userId] ?? null, $session);
        }
        usort($rows, static fn (array $a, array $b) => [mb_strtolower($a['nom']), mb_strtolower($a['prenom'])]
            <=> [mb_strtolower($b['nom']), mb_strtolower($b['prenom'])]);

        return new JsonResponse([
            'session' => $this->sessionPayload($session, new \DateTimeImmutable('today')),
            'enteredCount' => count($results),
            // Anciens adhérents (sans compte) importés : visibles dans le backend uniquement.
            'legacyCount' => count($this->results->findLegacyBySession($session)),
            'data' => $rows,
        ]);
    }

    /**
     * État en direct de la feuille (voir StaffLiveStateTrait) : les temps déjà saisis. Interrogé
     * toutes les quelques secondes par l'appli pour que plusieurs chronométreurs voient en direct
     * les temps saisis par les autres.
     */
    #[Route('/api/staff/perf-tests/{id}/state', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function live(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);
        $session = $this->findSession($id);

        $rows = [];
        foreach ($this->results->findBySessionIndexedByUser($session) as $userId => $result) {
            $rows[] = ['id' => $userId] + self::state($result, $session);
        }
        usort($rows, static fn (array $a, array $b) => $a['id'] <=> $b['id']);

        return $this->liveState($request, ['results' => $rows]);
    }

    /**
     * Enregistre (ou efface, si vide) le temps d'un adhérent. Body : { time: string }
     * (« 5:42 », « 1:02:15 » ou un nombre de secondes). Réponse : nouvel état ; un
     * temps hors de la fourchette plausible de l'épreuve est enregistré mais signalé.
     */
    #[Route('/api/staff/perf-tests/{id}/results/{userId}', methods: ['PUT'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function save(int $id, int $userId, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);
        $session = $this->findSession($id);
        $user = $this->users->find($userId) ?? throw $this->createNotFoundException('Adhérent introuvable.');

        $payload = json_decode($request->getContent(), true);
        $raw = is_array($payload) ? trim((string) ($payload['time'] ?? '')) : '';
        $result = $this->results->findOneBySessionAndUser($session, $user);

        if ($raw === '') {
            if ($result !== null) {
                $this->em->remove($result);
                $this->em->flush();
            }
            return new JsonResponse(self::state(null, $session));
        }

        $seconds = PerfTestResult::parse($raw);
        if ($seconds === null) {
            return new JsonResponse(
                ['error' => 'Temps illisible : saisissez par ex. 5:42 (min:s) ou 1:02:15 (h:min:s).'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($result === null) {
            $result = new PerfTestResult($session, $user, $seconds, $viewer);
            $this->em->persist($result);
        } else {
            $result->setTime($seconds, $viewer);
        }
        $this->em->flush();

        return new JsonResponse(self::state($result, $session));
    }

    private function findSession(int $id): PerfTestSession
    {
        return $this->sessions->find($id) ?? throw $this->createNotFoundException('Prise de temps introuvable.');
    }

    /** @return array<string, mixed> */
    private function sessionPayload(PerfTestSession $s, \DateTimeImmutable $today): array
    {
        $startsAt = $s->getDate()->format('Y-m-d');
        $endsAt = $s->getPeriodEnd()->format('Y-m-d');
        $day = $today->format('Y-m-d');

        return [
            'id' => $s->getId(),
            'test' => $s->getTest()->value,
            'icon' => $s->getTest()->icon(),
            'label' => $s->getTestLabel(),
            'poolLength' => $s->getPoolLength(),
            'datesLabel' => $s->getDatesLabel(),
            'notes' => $s->getNotes(),
            // « en cours » : aujourd'hui dans la période ; « à venir » : pas encore commencée.
            'status' => $startsAt > $day ? 'upcoming' : ($endsAt >= $day ? 'ongoing' : 'past'),
        ];
    }

    /** @return array{seconds: ?int, time: ?string, by: ?string, warning: ?string} */
    private static function state(?PerfTestResult $r, PerfTestSession $session): array
    {
        $warning = null;
        if ($r !== null) {
            [$min, $max] = $session->getTest()->plausibleSeconds();
            if ($r->getTimeSeconds() < $min || $r->getTimeSeconds() > $max) {
                $warning = sprintf(
                    'Temps inhabituel pour cette épreuve (attendu entre %s et %s) : vérifiez la saisie.',
                    PerfTestResult::format($min),
                    PerfTestResult::format($max),
                );
            }
        }

        return [
            'seconds' => $r?->getTimeSeconds(),
            'time' => $r !== null ? PerfTestResult::format($r->getTimeSeconds()) : null,
            'by' => $r?->getEnteredBy()?->getFullName(),
            'warning' => $warning,
        ];
    }
}
