<?php

namespace App\Service\Training;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotRegistration;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Repository\TrainingSlotRegistrationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inscriptions aux entraînements « sur inscription » (places limitées, une seule inscription par semaine et par
 * adhérent, tous créneaux sur inscription confondus).
 *
 * Une inscription se rattache à l'OCCURRENCE d'un créneau (créneau de la semaine type + semaine, ou créneau
 * occasionnel) : voir TrainingSlotRegistration. Les « lignes » de créneau manipulées ici sont celles de
 * WeeklyScheduleService::buildWeek (tableaux), qui portent `registrationRequired` et `capacity`.
 *
 * Les inscriptions se ferment dès que le créneau a commencé (ou s'il est annulé), comme les votes de présence
 * aux événements.
 */
class TrainingRegistrationService
{
    private const DAY_NAMES = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    public function __construct(
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingSlotRegistrationRepository $registrations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Clé d'une occurrence de créneau : « t12 » (semaine type) ou « s5 » (occasionnel). */
    public static function occurrenceKey(array $row): string
    {
        return $row['templateId'] !== null ? 't'.$row['templateId'] : 's'.$row['id'];
    }

    /**
     * Ajoute à chaque ligne de la semaine la clé `registration` (null si le créneau n'est pas sur inscription) :
     * places prises, si c'est complet, si le viewer y est inscrit, si les inscriptions sont closes, et — une seule
     * inscription par semaine — son inscription sur un AUTRE créneau de la semaine, le cas échéant.
     * Retire les clés brutes `registrationRequired` et `capacity`.
     *
     * @param list<array<string, mixed>> $rows lignes de WeeklyScheduleService::buildWeek
     *
     * @return list<array<string, mixed>>
     */
    public function decorate(array $rows, User $viewer, \DateTimeImmutable $monday): array
    {
        $counts = [];
        $myKey = null;
        if (array_filter($rows, static fn (array $r) => $r['registrationRequired']) !== []) {
            foreach ($this->registrations->findKeysForWeek($monday) as $k) {
                $key = $k['templateId'] !== null ? 't'.$k['templateId'] : 's'.$k['slotId'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if ($k['userId'] === $viewer->getId()) {
                    $myKey = $key;
                }
            }
        }

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[self::occurrenceKey($row)] = $row;
        }

        $now = new \DateTimeImmutable();
        $out = [];
        foreach ($rows as $row) {
            $row['registration'] = $this->state($row, $counts, $myKey, $byKey, $monday, $now);
            unset($row['registrationRequired'], $row['capacity']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Ligne du créneau demandé et état de ses inscriptions pour le viewer ; null si le créneau n'existe pas cette
     * semaine-là ou n'est pas visible par le viewer.
     *
     * @return array{row: array<string, mixed>, registration: ?array<string, mixed>}|null
     */
    public function stateFor(User $viewer, \DateTimeImmutable $week, ?int $templateId, ?int $slotId): ?array
    {
        $monday = WeeklyScheduleService::snapToMonday($week);
        $rows = $this->schedule->buildWeek($monday, $viewer);
        $row = $this->findRow($rows, $templateId, $slotId);
        if ($row === null) {
            return null;
        }

        foreach ($this->decorate($rows, $viewer, $monday) as $decorated) {
            if (self::occurrenceKey($decorated) === self::occurrenceKey($row)) {
                return ['row' => $row, 'registration' => $decorated['registration']];
            }
        }

        return null;
    }

    /**
     * Inscrits d'une occurrence, dans l'ordre d'inscription.
     *
     * @param array<string, mixed> $row
     *
     * @return list<User>
     */
    public function participants(array $row, \DateTimeImmutable $monday): array
    {
        $templateId = $row['templateId'];
        $registrations = $this->registrations->findForOccurrence($templateId, $templateId === null ? (int) $row['id'] : null, $monday);

        return array_map(static fn (TrainingSlotRegistration $r) => $r->getUser(), $registrations);
    }

    /**
     * Inscrits de la semaine par occurrence (clé : voir occurrenceKey) — pour la page admin des créneaux.
     *
     * @return array<string, list<User>>
     */
    public function registrantsForWeek(\DateTimeImmutable $monday): array
    {
        $out = [];
        foreach ($this->registrations->findForWeekWithUsers($monday) as $registration) {
            $key = $registration->getTemplate() !== null
                ? 't'.$registration->getTemplate()->getId()
                : 's'.$registration->getSlot()?->getId();
            $out[$key][] = $registration->getUser();
        }

        return $out;
    }

    /**
     * Inscrit (`$registered` true) ou désinscrit l'adhérent. Renvoie null en cas de succès, sinon le refus :
     * `['code' => …, 'message' => …, 'status' => HTTP]` — créneau introuvable / pas sur inscription / clos /
     * complet / déjà inscrit à un autre créneau cette semaine.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public function setRegistered(User $user, \DateTimeImmutable $week, ?int $templateId, ?int $slotId, bool $registered): ?array
    {
        $monday = WeeklyScheduleService::snapToMonday($week);
        $rows = $this->schedule->buildWeek($monday, $user);
        $row = $this->findRow($rows, $templateId, $slotId);
        if ($row === null) {
            return self::refusal('not_found', 'Créneau introuvable.', 404);
        }
        if (!$row['registrationRequired']) {
            return self::refusal('not_required', 'Ce créneau n\'est pas sur inscription.', 422);
        }
        if ($row['isCancelled'] || $this->hasStarted($row, new \DateTimeImmutable())) {
            return self::refusal('closed', 'Les inscriptions sont closes : le créneau est annulé ou a déjà commencé.', 409);
        }

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[self::occurrenceKey($r)] = $r;
        }
        $key = self::occurrenceKey($row);

        try {
            // Transaction + verrou sur le créneau : deux inscriptions simultanées ne peuvent pas dépasser les places.
            return $this->em->wrapInTransaction(function () use ($user, $monday, $row, $key, $byKey, $registered): ?array {
                $template = null;
                $slot = null;
                if ($row['templateId'] !== null) {
                    $template = $this->em->find(TrainingSlotTemplate::class, $row['templateId'], LockMode::PESSIMISTIC_WRITE);
                } else {
                    $slot = $this->em->find(TrainingSlot::class, $row['id'], LockMode::PESSIMISTIC_WRITE);
                }
                if ($template === null && $slot === null) {
                    return self::refusal('not_found', 'Créneau introuvable.', 404);
                }

                $mine = $this->registrations->findOneByUserAndWeek($user, $monday);
                $mineKey = null;
                if ($mine !== null) {
                    $mineKey = $mine->getTemplate() !== null ? 't'.$mine->getTemplate()->getId() : 's'.$mine->getSlot()?->getId();
                }

                if (!$registered) {
                    if ($mine !== null && $mineKey === $key) {
                        $this->em->remove($mine);
                    }

                    return null;
                }
                if ($mine !== null) {
                    if ($mineKey === $key) {
                        return null; // déjà inscrit à ce créneau : rien à faire
                    }
                    $other = $byKey[$mineKey] ?? null;

                    return self::refusal('already_registered_this_week', sprintf(
                        'Vous êtes déjà inscrit(e) à %s cette semaine : une seule inscription par semaine est possible. '
                        .'Désinscrivez-vous de ce créneau pour pouvoir vous inscrire ici.',
                        $other !== null ? sprintf('« %s » (%s)', $other['title'], $this->describe($other)) : 'un autre créneau',
                    ), 409);
                }

                $capacity = $row['capacity'];
                if ($capacity !== null && $this->registrations->countForOccurrence($row['templateId'], $row['templateId'] === null ? (int) $row['id'] : null, $monday) >= $capacity) {
                    return self::refusal('full', 'Ce créneau est complet.', 409);
                }

                $this->em->persist(new TrainingSlotRegistration($user, $template, $slot, $monday));

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux demandes simultanées du même adhérent sur deux créneaux : la base garantit une seule inscription.
            return self::refusal('already_registered_this_week', 'Vous avez déjà une inscription cette semaine : une seule inscription par semaine est possible.', 409);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    public function findRow(array $rows, ?int $templateId, ?int $slotId): ?array
    {
        foreach ($rows as $row) {
            if ($templateId !== null ? $row['templateId'] === $templateId : ($row['templateId'] === null && $slotId !== null && $row['id'] === $slotId)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>                    $row
     * @param array<string, int>                      $counts
     * @param array<string, array<string, mixed>>     $byKey
     *
     * @return array<string, mixed>|null
     */
    private function state(array $row, array $counts, ?string $myKey, array $byKey, \DateTimeImmutable $monday, \DateTimeImmutable $now): ?array
    {
        if (!$row['registrationRequired']) {
            return null;
        }

        $key = self::occurrenceKey($row);
        $capacity = $row['capacity'];
        $count = $counts[$key] ?? 0;
        $mine = $myKey === $key;

        $other = null;
        if ($myKey !== null && !$mine) {
            $o = $byKey[$myKey] ?? null;
            $other = $o !== null
                ? ['title' => $o['title'], 'date' => $o['date'], 'startTime' => $o['startTime']]
                : ['title' => 'un autre créneau', 'date' => $monday->format('Y-m-d'), 'startTime' => null];
        }

        return [
            'capacity' => $capacity,
            'count' => $count,
            'full' => $capacity !== null && $count >= $capacity,
            'mine' => $mine,
            'closed' => $row['isCancelled'] || $this->hasStarted($row, $now),
            'other' => $other,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hasStarted(array $row, \DateTimeImmutable $now): bool
    {
        return new \DateTimeImmutable($row['date'].' '.$row['startTime']) <= $now;
    }

    /** « lun. 14/10 à 18:30 » */
    private function describe(array $row): string
    {
        $date = new \DateTimeImmutable($row['date']);

        return sprintf('%s %s à %s', self::DAY_NAMES[(int) $date->format('N')] ?? '', $date->format('d/m'), $row['startTime']);
    }

    /** @return array{code: string, message: string, status: int} */
    private static function refusal(string $code, string $message, int $status): array
    {
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }
}
