<?php

namespace App\Controller\Api;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotCheckIn;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Repository\TrainingSlotCheckInRepository;
use App\Repository\TrainingSlotRegistrationRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\Training\TrainingRegistrationService;
use App\Service\Training\WeeklyScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Émargement de la présence réelle aux entraînements SUR INSCRIPTION, depuis l'espace « Staff » de l'appli (même
 * logique que StaffCheckInController pour les événements) : la feuille part des inscrits et permet d'émarger aussi un
 * adhérent venu sans s'être inscrit.
 *
 * Une occurrence de créneau se désigne par un jeton `occ` : « t12.2026-10-12 » (créneau 12 de la semaine type, semaine
 * du 12 octobre) ou « s5 » (créneau occasionnel 5). Les réponses reprennent les formes de l'émargement des événements
 * pour que l'appli réutilise la même feuille : la clé `event` décrit le créneau, `vote` vaut « yes » (inscrit) ou
 * « none » (non inscrit).
 *
 * Réservé aux entraîneurs, encadrants, membres du CoDir et administrateurs.
 */
#[IsGranted('ROLE_USER')]
class StaffTrainingCheckInController extends AbstractController
{
    use StaffOnlyTrait;
    use StaffLiveStateTrait;

    private const OCC_REQUIREMENT = '[ts]\d+(?:\.\d{4}-\d{2}-\d{2})?';

    /** Inscrits d'abord, puis ceux qui ne sont pas inscrits. */
    private const VOTE_ORDER = ['yes' => 0, 'none' => 1];

    /** Fenêtre de la liste mobile : jours avant le début d'un créneau à venir, et après la fin d'un créneau passé. */
    private const WINDOW_DAYS = 5;

    public function __construct(
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingRegistrationService $registrationService,
        private readonly TrainingSlotRegistrationRepository $registrations,
        private readonly TrainingSlotCheckInRepository $checkIns,
        private readonly TrainingSlotRepository $slots,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AvatarService $avatars,
    ) {
    }

    /**
     * Entraînements sur inscription à émarger : ceux en cours ou à venir dont le début tombe dans les 5 prochains
     * jours (du plus proche au plus lointain), puis les terminés depuis moins de 5 jours (du plus récent au plus
     * ancien). Les créneaux annulés n'y figurent pas.
     */
    #[Route('/api/staff/check-in/trainings', methods: ['GET'])]
    public function trainings(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);

        $now = new \DateTimeImmutable();
        $today = new \DateTimeImmutable('today');
        $since = $today->modify('-'.self::WINDOW_DAYS.' days');
        $until = $today->modify('+'.(self::WINDOW_DAYS + 1).' days');

        $mondays = [];
        for ($monday = WeeklyScheduleService::snapToMonday($since); $monday < $until; $monday = $monday->modify('+7 days')) {
            $mondays[] = $monday;
        }
        $checked = $this->checkIns->countsForWeeks($mondays);

        $upcoming = [];
        $past = [];
        foreach ($mondays as $monday) {
            $registered = [];
            foreach ($this->registrations->findKeysForWeek($monday) as $k) {
                $key = $k['templateId'] !== null ? 't'.$k['templateId'] : 's'.$k['slotId'];
                $registered[$key] = ($registered[$key] ?? 0) + 1;
            }

            foreach ($this->schedule->buildWeek($monday) as $row) {
                if (!$row['registrationRequired'] || $row['isCancelled']) {
                    continue;
                }
                $start = new \DateTimeImmutable($row['date'].' '.$row['startTime']);
                $end = $start->modify('+'.$row['durationMinutes'].' minutes');
                if ($start >= $until || $end < $since) {
                    continue;
                }

                $payload = self::payload($row, $monday) + [
                    'registered' => $registered[TrainingRegistrationService::occurrenceKey($row)] ?? 0,
                    'checkedCount' => $checked[TrainingSlotCheckInRepository::key($row['templateId'], $row['templateId'] === null ? (int) $row['id'] : null, $monday->format('Y-m-d'))] ?? 0,
                ];
                if ($end < $now) {
                    $past[] = [$start, $payload];
                } else {
                    $upcoming[] = [$start, $payload];
                }
            }
        }

        usort($upcoming, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        usort($past, static fn (array $a, array $b) => $b[0] <=> $a[0]);

        return new JsonResponse(['data' => array_map(static fn (array $x) => $x[1], array_merge($upcoming, $past))]);
    }

    /** Feuille d'émargement d'un entraînement : les inscrits, puis tous les adhérents, avec leur état. */
    #[Route('/api/staff/check-in/trainings/{occ}', methods: ['GET'], requirements: ['occ' => self::OCC_REQUIREMENT])]
    public function sheet(string $occ): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);
        $target = $this->resolve($occ);

        $checkIns = $this->checkIns->findByOccurrenceIndexedByUser($target['templateId'], $target['slotId'], $target['monday']);

        /** @var array<int, array{user: User, vote: string}> $people */
        $people = [];
        foreach ($this->registrationService->registrationsOf($target['row'], $target['monday']) as $registration) {
            $people[(int) $registration->getUser()->getId()] = ['user' => $registration->getUser(), 'vote' => 'yes'];
        }
        foreach ($this->users->findActiveAdherentsForRecap() as $u) {
            $people[(int) $u->getId()] ??= ['user' => $u, 'vote' => 'none'];
        }
        // Adhérent devenu inactif mais déjà émargé : il reste sur la feuille.
        foreach ($checkIns as $userId => $c) {
            $people[$userId] ??= ['user' => $c->getUser(), 'vote' => 'none'];
        }

        $rows = [];
        foreach ($people as $userId => $p) {
            $rows[] = [
                'id' => $userId,
                'nom' => $p['user']->getNom(),
                'prenom' => $p['user']->getPrenom(),
                'avatarUrl' => $this->avatars->urlFor($p['user']),
                'vote' => $p['vote'],
            ] + self::state($checkIns[$userId] ?? null);
        }
        usort($rows, static fn (array $a, array $b) => [self::VOTE_ORDER[$a['vote']], mb_strtolower($a['nom']), mb_strtolower($a['prenom'])]
            <=> [self::VOTE_ORDER[$b['vote']], mb_strtolower($b['nom']), mb_strtolower($b['prenom'])]);

        return new JsonResponse([
            'event' => self::payload($target['row'], $target['monday']),
            'checkedCount' => count($checkIns),
            'data' => $rows,
        ]);
    }

    /** État en direct de la feuille (voir StaffLiveStateTrait) : émargés (heure et auteur) et inscrits. */
    #[Route('/api/staff/check-in/trainings/{occ}/state', methods: ['GET'], requirements: ['occ' => self::OCC_REQUIREMENT])]
    public function live(string $occ, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);
        $target = $this->resolve($occ);

        $checked = [];
        foreach ($this->checkIns->findByOccurrenceIndexedByUser($target['templateId'], $target['slotId'], $target['monday']) as $userId => $checkIn) {
            $checked[] = ['id' => $userId] + self::state($checkIn);
        }
        usort($checked, static fn (array $a, array $b) => $a['id'] <=> $b['id']);

        $votes = [];
        foreach ($this->registrationService->registrationsOf($target['row'], $target['monday']) as $registration) {
            $votes[] = [(int) $registration->getUser()->getId(), 'yes'];
        }
        usort($votes, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $this->liveState($request, ['checkedCount' => count($checked), 'checked' => $checked, 'votes' => $votes]);
    }

    /** Coche ou décoche la présence d'un adhérent. Body : { checked: bool }. Idempotent. */
    #[Route('/api/staff/check-in/trainings/{occ}/members/{userId}', methods: ['PUT'], requirements: ['occ' => self::OCC_REQUIREMENT, 'userId' => '\d+'])]
    public function toggle(string $occ, int $userId, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCheckIn($viewer);
        $target = $this->resolve($occ);
        $user = $this->users->find($userId);
        if ($user === null) {
            throw $this->createNotFoundException('Adhérent introuvable.');
        }

        $payload = json_decode($request->getContent(), true);
        $wanted = is_array($payload) && ($payload['checked'] ?? false) === true;

        $checkIn = $this->checkIns->findOneByOccurrenceAndUser($target['templateId'], $target['slotId'], $target['monday'], $user);
        if ($wanted && $checkIn === null) {
            $checkIn = new TrainingSlotCheckIn(
                $user,
                $target['templateId'] !== null ? $this->em->getReference(TrainingSlotTemplate::class, $target['templateId']) : null,
                $target['slotId'] !== null ? $this->em->getReference(TrainingSlot::class, $target['slotId']) : null,
                $target['monday'],
                $viewer,
            );
            $this->em->persist($checkIn);
            $this->em->flush();
        } elseif (!$wanted && $checkIn !== null) {
            $this->em->remove($checkIn);
            $this->em->flush();
            $checkIn = null;
        }

        return new JsonResponse(self::state($checkIn));
    }

    /**
     * Retrouve l'occurrence désignée par le jeton ; 404 si elle n'existe pas ou n'est pas sur inscription.
     *
     * @return array{row: array<string, mixed>, monday: \DateTimeImmutable, templateId: ?int, slotId: ?int}
     */
    private function resolve(string $occ): array
    {
        $templateId = null;
        $slotId = null;
        if (preg_match('/^t(\d+)\.(\d{4}-\d{2}-\d{2})$/', $occ, $m)) {
            $templateId = (int) $m[1];
            $monday = WeeklyScheduleService::snapToMonday(new \DateTimeImmutable($m[2]));
        } elseif (preg_match('/^s(\d+)$/', $occ, $m)) {
            $slot = $this->slots->find((int) $m[1]);
            if ($slot === null) {
                throw $this->createNotFoundException('Créneau introuvable.');
            }
            $slotId = (int) $m[1];
            $monday = WeeklyScheduleService::snapToMonday($slot->getWeekStartsAt());
        } else {
            throw $this->createNotFoundException('Créneau introuvable.');
        }

        $row = $this->registrationService->findRow($this->schedule->buildWeek($monday), $templateId, $slotId);
        if ($row === null || !$row['registrationRequired']) {
            throw $this->createNotFoundException('Créneau introuvable ou non sur inscription.');
        }

        return ['row' => $row, 'monday' => $monday, 'templateId' => $templateId, 'slotId' => $slotId];
    }

    /**
     * Description du créneau, sous la forme d'un événement (voir la description de la classe).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function payload(array $row, \DateTimeImmutable $monday): array
    {
        $start = new \DateTimeImmutable($row['date'].' '.$row['startTime']);

        return [
            'id' => (int) ($row['templateId'] ?? $row['id']),
            'occ' => $row['templateId'] !== null ? 't'.$row['templateId'].'.'.$monday->format('Y-m-d') : 's'.$row['id'],
            'title' => $row['title'],
            'startsAt' => $start->format(\DATE_ATOM),
            'endsAt' => $start->modify('+'.$row['durationMinutes'].' minutes')->format(\DATE_ATOM),
            'lastDay' => $row['date'],
            'isAllDay' => false,
            'location' => $row['location'],
            'sportIcon' => $row['sportIcon'],
            'capacity' => $row['capacity'],
        ];
    }

    /** @return array{checked: bool, checkedAt: ?string, checkedBy: ?string} */
    private static function state(?TrainingSlotCheckIn $c): array
    {
        return [
            'checked' => $c !== null,
            'checkedAt' => $c?->getCheckedAt()->format('H:i'),
            'checkedBy' => $c?->getCheckedBy()?->getFullName(),
        ];
    }
}
