<?php

namespace App\Service\Training;

use App\Entity\StaffPresence;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\StaffDayUnavailabilityRepository;
use App\Repository\StaffPresenceRepository;
use App\Repository\StaffWeekUnavailabilityRepository;

/**
 * Récap des heures encadrées sur une semaine, par entraîneur puis par encadrant : partagé par la page
 * « Présence entraînements » (backend) et l'e-mail envoyé chaque samedi soir aux responsables RH (entraîneurs
 * uniquement). Chaque personne porte aussi ses absences déclarées (semaine entière ou jours précis, avec motif).
 *
 * Règles de calcul :
 *  - une présence compte quand la personne est « présente » (statut scheduled ou attended, comme la page) ;
 *  - seuls les créneaux d'entraînement comptent (pas les tâches hors entraînement) ;
 *  - un créneau annulé ne compte pas ;
 *  - la durée est celle du créneau tel qu'il est aujourd'hui (une modification d'horaire se répercute).
 * Une personne ne peut pas être à la fois entraîneur et encadrant (règle de la fiche adhérent).
 */
class StaffHoursRecapService
{
    private const SECTIONS = [
        'entraineur' => 'Entraîneurs',
        'encadrant' => 'Encadrants',
    ];

    private const DAY_ABBR = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    public function __construct(
        private readonly StaffPresenceRepository $presences,
        private readonly StaffWeekUnavailabilityRepository $weekAbsences,
        private readonly StaffDayUnavailabilityRepository $dayAbsences,
    ) {
    }

    /**
     * `$includeEncadrants` à false : récap des seuls entraîneurs (e-mail aux responsables RH) ; le total n'inclut
     * alors pas non plus les encadrants.
     *
     * @return array{
     *     sections: list<array{
     *         key: string,
     *         label: string,
     *         rows: list<array{user: User, sessions: int, minutes: int, duration: string, details: list<string>, absences: list<string>}>,
     *         totalSessions: int,
     *         totalMinutes: int,
     *         total: string
     *     }>,
     *     totalMinutes: int,
     *     total: string
     * }
     */
    public function forWeek(\DateTimeImmutable $weekStartsAt, bool $includeEncadrants = true): array
    {
        $monday = WeeklyScheduleService::snapToMonday($weekStartsAt);

        // Personnes suivies : tout le staff actif (même à 0 h, pour que le récap soit complet), puis ceux qui
        // ont des heures cette semaine sans plus être actifs ou avoir encore le profil.
        /** @var array<int, array{user: User, section: string}> $members */
        $members = [];
        foreach ($this->presences->findActiveStaffByProfile(Profile::Entraineur) as $user) {
            $members[(int) $user->getId()] = ['user' => $user, 'section' => 'entraineur'];
        }
        if ($includeEncadrants) {
            foreach ($this->presences->findActiveStaffByProfile(Profile::Encadrant) as $user) {
                $members[(int) $user->getId()] ??= ['user' => $user, 'section' => 'encadrant'];
            }
        }

        /** @var array<int, array{sessions: int, minutes: int, details: list<array{sort: string, label: string}>}> $stats */
        $stats = [];
        foreach ($this->presences->findStaffPresencesForWeekGroupedByUser($monday) as $userId => $list) {
            foreach ($list as $presence) {
                $slot = $presence->getSlot();
                if ($slot === null || $slot->isCancelled() || $presence->getStatus() === StaffPresence::STATUS_UNAVAILABLE) {
                    continue;
                }

                $date = $slot->getWeekStartsAt()->modify(sprintf('+%d days', $slot->getDayOfWeek() - 1));
                $start = $slot->getStartTime();
                $end = $start->modify(sprintf('+%d minutes', $slot->getDurationMinutes()));

                $stats[$userId] ??= ['sessions' => 0, 'minutes' => 0, 'details' => []];
                ++$stats[$userId]['sessions'];
                $stats[$userId]['minutes'] += $slot->getDurationMinutes();
                $stats[$userId]['details'][] = [
                    'sort' => $date->format('Y-m-d').' '.$start->format('H:i'),
                    'label' => sprintf(
                        '%s %s–%s %s',
                        self::DAY_ABBR[$slot->getDayOfWeek()] ?? '?',
                        $start->format('H:i'),
                        $end->format('H:i'),
                        $slot->getTitle(),
                    ),
                ];

                $members[(int) $userId] ??= [
                    'user' => $presence->getUser(),
                    'section' => $presence->getUser()->isEntraineur() ? 'entraineur' : 'encadrant',
                ];
            }
        }

        $absences = $this->absencesByUser($monday);

        $sections = [];
        foreach (self::SECTIONS as $key => $label) {
            if ($key === 'encadrant' && !$includeEncadrants) {
                continue;
            }
            $rows = [];
            foreach ($members as $userId => $member) {
                if ($member['section'] !== $key) {
                    continue;
                }
                $s = $stats[$userId] ?? ['sessions' => 0, 'minutes' => 0, 'details' => []];
                usort($s['details'], static fn (array $a, array $b) => $a['sort'] <=> $b['sort']);
                $rows[] = [
                    'user' => $member['user'],
                    'sessions' => $s['sessions'],
                    'minutes' => $s['minutes'],
                    'duration' => self::formatMinutes($s['minutes']),
                    'details' => array_map(static fn (array $d) => $d['label'], $s['details']),
                    'absences' => $absences[$userId] ?? [],
                ];
            }
            usort($rows, static fn (array $a, array $b) => [mb_strtolower($a['user']->getNom()), mb_strtolower($a['user']->getPrenom())]
                <=> [mb_strtolower($b['user']->getNom()), mb_strtolower($b['user']->getPrenom())]);

            $totalMinutes = array_sum(array_column($rows, 'minutes'));
            $sections[] = [
                'key' => $key,
                'label' => $label,
                'rows' => $rows,
                'totalSessions' => array_sum(array_column($rows, 'sessions')),
                'totalMinutes' => $totalMinutes,
                'total' => self::formatMinutes($totalMinutes),
            ];
        }

        $totalMinutes = array_sum(array_column($sections, 'totalMinutes'));

        return ['sections' => $sections, 'totalMinutes' => $totalMinutes, 'total' => self::formatMinutes($totalMinutes)];
    }

    /**
     * Absences déclarées de la semaine, une phrase par motif et par personne :
     * « 🤒 Maladie : toute la semaine », « 🏖️ Vacances : mar. 14/10, mer. 15/10 ».
     *
     * @return array<int, list<string>> par identifiant d'utilisateur
     */
    private function absencesByUser(\DateTimeImmutable $monday): array
    {
        $lines = [];

        foreach ($this->weekAbsences->findForWeek($monday) as $absence) {
            $reason = $absence->getReason();
            $lines[(int) $absence->getUser()->getId()][] = sprintf(
                '%s %s : toute la semaine',
                $reason?->icon() ?? '❌',
                $reason?->label() ?? 'Non dispo',
            );
        }

        // Jours précis : regroupés par motif, dans l'ordre chronologique.
        /** @var array<int, array<string, array{label: string, days: list<string>}>> $byReason */
        $byReason = [];
        $days = $this->dayAbsences->findForWeek($monday);
        usort($days, static fn ($a, $b) => $a->getDate() <=> $b->getDate());
        foreach ($days as $absence) {
            $reason = $absence->getReason();
            $userId = (int) $absence->getUser()->getId();
            $date = $absence->getDate();
            $key = $reason?->value ?? '-';
            $byReason[$userId][$key] ??= [
                'label' => sprintf('%s %s', $reason?->icon() ?? '❌', $reason?->label() ?? 'Non dispo'),
                'days' => [],
            ];
            $byReason[$userId][$key]['days'][] = (self::DAY_ABBR[(int) $date->format('N')] ?? '?').' '.$date->format('d/m');
        }
        foreach ($byReason as $userId => $groups) {
            foreach ($groups as $group) {
                $lines[$userId][] = $group['label'].' : '.implode(', ', $group['days']);
            }
        }

        return $lines;
    }

    /** « Semaine du lundi 12 octobre au dimanche 18 octobre 2026 ». */
    public function weekLabel(\DateTimeImmutable $weekStartsAt): string
    {
        $monday = WeeklyScheduleService::snapToMonday($weekStartsAt);
        $fmtStart = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM');
        $fmtEnd = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM y');

        return sprintf('Semaine du %s au %s', (string) $fmtStart->format($monday), (string) $fmtEnd->format($monday->modify('+6 days')));
    }

    /** 0 → « 0 h », 45 → « 45 min », 180 → « 3 h », 193 → « 3 h 13 ». */
    public static function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes === 0 ? '0 h' : $minutes.' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? $h.' h' : sprintf('%d h %02d', $h, $m);
    }
}
