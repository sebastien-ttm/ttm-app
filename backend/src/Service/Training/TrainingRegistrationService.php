<?php

namespace App\Service\Training;

use App\Entity\TrainingRegistrationUnlock;
use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotRegistration;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Repository\TrainingRegistrationUnlockRepository;
use App\Repository\TrainingSlotRegistrationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inscriptions aux entraînements « sur inscription » (places limitées ; une seule inscription par semaine et par
 * adhérent, tous créneaux sur inscription confondus — deux quand un entraîneur a débloqué la semaine).
 *
 * Une inscription se rattache à l'OCCURRENCE d'un créneau (créneau de la semaine type + semaine, ou créneau
 * occasionnel) : voir TrainingSlotRegistration. Les « lignes » de créneau manipulées ici sont celles de
 * WeeklyScheduleService::buildWeek (tableaux), qui portent `registrationRequired` et `capacity`.
 *
 * Pour les adhérents, les inscriptions se ferment dès que le créneau a commencé (ou s'il est annulé), comme les
 * votes de présence aux événements. Un entraîneur, lui, peut inscrire / désinscrire à la main (`$asCoach`).
 */
class TrainingRegistrationService
{
    private const DAY_NAMES = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    public function __construct(
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingSlotRegistrationRepository $registrations,
        private readonly TrainingRegistrationUnlockRepository $unlocks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Clé d'une occurrence de créneau : « t12 » (semaine type) ou « s5 » (occasionnel). */
    public static function occurrenceKey(array $row): string
    {
        return $row['templateId'] !== null ? 't'.$row['templateId'] : 's'.$row['id'];
    }

    /** Inscriptions autorisées par adhérent sur la semaine : 1, ou 2 quand un entraîneur l'a débloquée. */
    public function limitFor(\DateTimeImmutable $monday): int
    {
        return $this->unlocks->isUnlocked($monday) ? TrainingRegistrationUnlock::UNLOCKED_LIMIT : 1;
    }

    public function isUnlocked(\DateTimeImmutable $monday): bool
    {
        return $this->unlocks->isUnlocked($monday);
    }

    /** Débloque la semaine (2 inscriptions par adhérent). Sans effet si elle l'est déjà. */
    public function unlock(\DateTimeImmutable $monday, ?User $by): void
    {
        if ($this->unlocks->isUnlocked($monday)) {
            return;
        }
        try {
            $this->em->persist(new TrainingRegistrationUnlock($monday, $by));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Débloquée entre-temps par un autre entraîneur : même résultat.
        }
    }

    /**
     * Rétablit la règle « une seule inscription ». Les adhérents déjà inscrits à deux créneaux gardent leurs deux
     * inscriptions ; ils ne peuvent simplement plus en ajouter.
     */
    public function relock(\DateTimeImmutable $monday): void
    {
        $unlock = $this->unlocks->findOneByWeek($monday);
        if ($unlock !== null) {
            $this->em->remove($unlock);
            $this->em->flush();
        }
    }

    /**
     * Ajoute à chaque ligne de la semaine la clé `registration` (null si le créneau n'est pas sur inscription) :
     * places prises, si c'est complet, si le viewer y est inscrit, si les inscriptions sont closes, et — règle du
     * nombre d'inscriptions par semaine — ses inscriptions sur d'AUTRES créneaux et si la limite l'empêche de
     * s'inscrire ici. Retire les clés brutes `registrationRequired` et `capacity`.
     *
     * @param list<array<string, mixed>> $rows lignes de WeeklyScheduleService::buildWeek
     *
     * @return list<array<string, mixed>>
     */
    public function decorate(array $rows, User $viewer, \DateTimeImmutable $monday): array
    {
        $counts = [];
        $myKeys = [];
        $limit = 1;
        if (array_filter($rows, static fn (array $r) => $r['registrationRequired']) !== []) {
            $limit = $this->limitFor($monday);
            foreach ($this->registrations->findKeysForWeek($monday) as $k) {
                $key = $k['templateId'] !== null ? 't'.$k['templateId'] : 's'.$k['slotId'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if ($k['userId'] === $viewer->getId()) {
                    $myKeys[] = $key;
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
            $row['registration'] = $this->state($row, $counts, $myKeys, $limit, $byKey, $monday, $now);
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
        return array_map(static fn (TrainingSlotRegistration $r) => $r->getUser(), $this->registrationsOf($row, $monday));
    }

    /**
     * Inscriptions d'une occurrence (avec l'adhérent et la date d'inscription), dans l'ordre d'inscription.
     *
     * @param array<string, mixed> $row
     *
     * @return list<TrainingSlotRegistration>
     */
    public function registrationsOf(array $row, \DateTimeImmutable $monday): array
    {
        $templateId = $row['templateId'];

        return $this->registrations->findForOccurrence($templateId, $templateId === null ? (int) $row['id'] : null, $monday);
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
     * complet / limite d'inscriptions de la semaine atteinte.
     *
     * `$asCoach` : inscription faite à la main par un entraîneur depuis le backend — pas de filtre d'audience, pas de
     * clôture au début du créneau (corrections après coup possibles ; seul un créneau annulé refuse une inscription),
     * et `$ignoreCapacity` permet de dépasser les places. La limite d'inscriptions par semaine s'applique toujours.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public function setRegistered(
        User $user,
        \DateTimeImmutable $week,
        ?int $templateId,
        ?int $slotId,
        bool $registered,
        bool $asCoach = false,
        bool $ignoreCapacity = false,
    ): ?array {
        $monday = WeeklyScheduleService::snapToMonday($week);
        $rows = $this->schedule->buildWeek($monday, $asCoach ? null : $user);
        $row = $this->findRow($rows, $templateId, $slotId);
        if ($row === null) {
            return self::refusal('not_found', 'Créneau introuvable.', 404);
        }
        if (!$row['registrationRequired']) {
            return self::refusal('not_required', 'Ce créneau n\'est pas sur inscription.', 422);
        }
        if ($asCoach) {
            if ($registered && $row['isCancelled']) {
                return self::refusal('closed', 'Ce créneau est annulé : on ne peut plus y inscrire personne.', 409);
            }
        } elseif ($row['isCancelled'] || $this->hasStarted($row, new \DateTimeImmutable())) {
            return self::refusal('closed', 'Les inscriptions sont closes : le créneau est annulé ou a déjà commencé.', 409);
        }

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[self::occurrenceKey($r)] = $r;
        }
        $key = self::occurrenceKey($row);
        $limit = $this->limitFor($monday);

        try {
            // Transaction + verrou sur le créneau : deux inscriptions simultanées ne peuvent pas dépasser les places.
            return $this->em->wrapInTransaction(function () use ($user, $monday, $row, $key, $byKey, $registered, $asCoach, $ignoreCapacity, $limit): ?array {
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

                $mineList = $this->registrations->findByUserAndWeek($user, $monday);
                /** @var array<string, TrainingSlotRegistration> $mineByKey */
                $mineByKey = [];
                foreach ($mineList as $m) {
                    $mineByKey[$m->getTemplate() !== null ? 't'.$m->getTemplate()->getId() : 's'.$m->getSlot()?->getId()] = $m;
                }

                if (!$registered) {
                    if (isset($mineByKey[$key])) {
                        $this->em->remove($mineByKey[$key]);
                    }

                    return null;
                }
                if (isset($mineByKey[$key])) {
                    return null; // déjà inscrit à ce créneau : rien à faire
                }
                if (count($mineList) >= $limit) {
                    return self::refusal('already_registered_this_week', $this->limitMessage($mineByKey, $byKey, $limit, $asCoach), 409);
                }

                $capacity = $row['capacity'];
                if (!$ignoreCapacity && $capacity !== null
                    && $this->registrations->countForOccurrence($row['templateId'], $row['templateId'] === null ? (int) $row['id'] : null, $monday) >= $capacity) {
                    return self::refusal('full', 'Ce créneau est complet.', 409);
                }

                // Rang libre de la semaine pour cet adhérent (l'unicité en base départage deux demandes simultanées).
                $used = array_map(static fn (TrainingSlotRegistration $m) => $m->getPosition(), $mineList);
                $position = in_array(1, $used, true) ? 2 : 1;

                $this->em->persist(new TrainingSlotRegistration($user, $template, $slot, $monday, $position));

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux demandes simultanées du même adhérent : la base garantit la limite d'inscriptions de la semaine.
            return self::refusal('already_registered_this_week', 'Une autre inscription vient d\'être enregistrée pour cet adhérent cette semaine. Rechargez et réessayez.', 409);
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
     * @param array<string, TrainingSlotRegistration> $mineByKey
     * @param array<string, array<string, mixed>>     $byKey
     */
    private function limitMessage(array $mineByKey, array $byKey, int $limit, bool $asCoach): string
    {
        $labels = [];
        foreach (array_keys($mineByKey) as $k) {
            $o = $byKey[$k] ?? null;
            $labels[] = $o !== null ? sprintf('« %s » (%s)', $o['title'], $this->describe($o)) : 'un autre créneau';
        }
        $list = implode(' et ', $labels);

        if ($asCoach) {
            return sprintf(
                'Cet adhérent est déjà inscrit à %s cette semaine (maximum %d). Désinscrivez-le d\'abord%s.',
                $list,
                $limit,
                $limit === 1 ? ', ou débloquez la 2e inscription depuis la page des créneaux de la semaine' : '',
            );
        }

        return $limit === 1
            ? sprintf('Vous êtes déjà inscrit(e) à %s cette semaine : une seule inscription par semaine est possible. Désinscrivez-vous de ce créneau pour pouvoir vous inscrire ici.', $list)
            : sprintf('Vous êtes déjà inscrit(e) à %s cette semaine : deux inscriptions maximum. Désinscrivez-vous d\'un de ces créneaux pour pouvoir vous inscrire ici.', $list);
    }

    /**
     * @param array<string, mixed>                $row
     * @param array<string, int>                  $counts
     * @param list<string>                        $myKeys
     * @param array<string, array<string, mixed>> $byKey
     *
     * @return array<string, mixed>|null
     */
    private function state(array $row, array $counts, array $myKeys, int $limit, array $byKey, \DateTimeImmutable $monday, \DateTimeImmutable $now): ?array
    {
        if (!$row['registrationRequired']) {
            return null;
        }

        $key = self::occurrenceKey($row);
        $capacity = $row['capacity'];
        $count = $counts[$key] ?? 0;
        $mine = in_array($key, $myKeys, true);

        $others = [];
        foreach ($myKeys as $k) {
            if ($k === $key) {
                continue;
            }
            $o = $byKey[$k] ?? null;
            $others[] = $o !== null
                ? ['title' => $o['title'], 'date' => $o['date'], 'startTime' => $o['startTime']]
                : ['title' => 'un autre créneau', 'date' => $monday->format('Y-m-d'), 'startTime' => null];
        }
        // La limite d'inscriptions de la semaine interdit de s'inscrire ici (déjà `limit` inscriptions ailleurs).
        $blocked = !$mine && count($myKeys) >= $limit;

        return [
            'capacity' => $capacity,
            'count' => $count,
            'full' => $capacity !== null && $count >= $capacity,
            'mine' => $mine,
            'closed' => $row['isCancelled'] || $this->hasStarted($row, $now),
            // Inscriptions autorisées par adhérent cette semaine (1, ou 2 si un entraîneur a débloqué la semaine).
            'limit' => $limit,
            'others' => $others,
            'blocked' => $blocked,
            // Rétrocompatibilité (anciennes versions de l'appli) : la 1re autre inscription, seulement si elle bloque.
            'other' => $blocked && $others !== [] ? $others[0] : null,
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
