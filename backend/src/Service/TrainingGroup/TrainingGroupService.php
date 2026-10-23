<?php

namespace App\Service\TrainingGroup;

use App\Entity\TrainingGroup;
use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Repository\TrainingGroupRepository;
use App\Service\Training\WeeklyScheduleService;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cycle de vie des groupes d'entraînement : séances auxquelles on peut les adosser (vélo et course à pied), durée de
 * validité d'après le planning, création, choix d'un groupe par les adhérents (avec le nombre de places), gestion des
 * encadrants et des adhérents (avec notification des personnes ajoutées), nettoyage.
 *
 * Une « séance » est une ligne de WeeklyScheduleService::buildWeek complétée de ses heures de début et de fin et de la
 * clé de son occurrence (`occ` : « t12:2026-10-26 » pour un créneau de la semaine type, « s45 » pour un occasionnel).
 */
class TrainingGroupService
{
    /** Un groupe expire au plus tard 10 jours après la fin de sa séance, même si le planning n'a rien d'autre à proposer. */
    private const MAX_AFTER_SESSION = '+10 days';
    /** On propose les séances des 14 prochains jours. */
    private const SESSION_HORIZON = '+14 days';
    /** Semaines de planning parcourues : l'horizon, plus de quoi trouver le créneau qui suit la dernière séance. */
    private const PLANNING_WEEKS = 5;
    /** Les groupes terminés sont supprimés (données et fichiers) après ce délai. */
    private const PURGE_AFTER = '-7 days';

    public function __construct(
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingGroupRepository $groups,
        private readonly TrainingGroupFileService $files,
        private readonly EntityManagerInterface $em,
        private readonly WebPushNotifier $push,
    ) {
    }

    /**
     * Toutes les séances non annulées du planning à partir d'hier (sert à trouver le créneau suivant), la plus proche
     * d'abord.
     *
     * @return list<array{row: array<string, mixed>, title: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, monday: \DateTimeImmutable, occ: string}>
     */
    private function sessions(\DateTimeImmutable $now): array
    {
        $firstMonday = WeeklyScheduleService::snapToMonday($now->modify('-1 day'));
        $out = [];
        for ($week = 0; $week < self::PLANNING_WEEKS; ++$week) {
            $monday = $firstMonday->modify('+'.$week.' weeks');
            foreach ($this->schedule->buildWeek($monday) as $row) {
                if ($row['isCancelled']) {
                    continue;
                }
                $start = new \DateTimeImmutable($row['date'].' '.$row['startTime']);
                $out[] = [
                    'row' => $row,
                    'title' => (string) $row['title'],
                    'startsAt' => $start,
                    'endsAt' => $start->modify('+'.(int) $row['durationMinutes'].' minutes'),
                    'monday' => $monday,
                    'occ' => self::occurrenceKey($row, $monday),
                ];
            }
        }
        usort($out, static fn (array $a, array $b) => $a['startsAt'] <=> $b['startsAt']);

        return $out;
    }

    /** Un groupe peut-il être adossé à cette séance ? Vélo ou course à pied, non commencée depuis plus de sa durée. */
    private static function isEligible(array $session, \DateTimeImmutable $now): bool
    {
        return in_array($session['row']['sport'], TrainingGroup::SPORTS, true) && $session['endsAt'] > $now;
    }

    /**
     * Séances auxquelles on peut adosser un nouveau groupe : vélo et course à pied, en cours ou à venir dans les 14
     * prochains jours.
     *
     * @return list<array{row: array<string, mixed>, title: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, monday: \DateTimeImmutable, occ: string}>
     */
    public function eligibleSessions(\DateTimeImmutable $now): array
    {
        $limit = $now->modify(self::SESSION_HORIZON);

        return array_values(array_filter(
            $this->sessions($now),
            static fn (array $s) => self::isEligible($s, $now) && $s['startsAt'] <= $limit,
        ));
    }

    /**
     * La séance désignée (créneau de la semaine type + semaine, ou créneau occasionnel) si un groupe peut lui être
     * adossé, sinon null.
     *
     * @return array{row: array<string, mixed>, title: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, monday: \DateTimeImmutable, occ: string}|null
     */
    public function findEligibleSession(\DateTimeImmutable $week, ?int $templateId, ?int $slotId, \DateTimeImmutable $now): ?array
    {
        $monday = WeeklyScheduleService::snapToMonday($week);
        foreach ($this->eligibleSessions($now) as $session) {
            if ($session['monday'] != $monday) {
                continue;
            }
            $row = $session['row'];
            if ($templateId !== null ? $row['templateId'] === $templateId : ($row['templateId'] === null && $slotId !== null && $row['id'] === $slotId)) {
                return $session;
            }
        }

        return null;
    }

    /** Clé de l'occurrence d'une ligne du planning (voir TrainingGroup::occurrenceKey). */
    public static function occurrenceKey(array $row, \DateTimeImmutable $monday): string
    {
        return $row['templateId'] !== null ? 't'.$row['templateId'].':'.$monday->format('Y-m-d') : 's'.$row['id'];
    }

    /**
     * Fin de validité d'un groupe adossé à `$session` : début du créneau qui suit (« le groupe reste jusqu'au prochain
     * créneau »), sans jamais finir avant la fin de la séance ni dépasser 10 jours après elle.
     *
     * @param array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable} $session
     */
    private function expiryFor(array $session, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $expiresAt = $session['endsAt'];
        foreach ($this->sessions($now) as $other) {
            if ($other['startsAt'] > $session['startsAt']) {
                if ($other['startsAt'] > $expiresAt) {
                    $expiresAt = $other['startsAt'];
                }
                break;
            }
        }
        $cap = $session['endsAt']->modify(self::MAX_AFTER_SESSION);

        return $expiresAt < $cap ? $expiresAt : $cap;
    }

    /**
     * Crée et enregistre un groupe adossé à `$session` (voir eligibleSessions). L'appelant a déjà vérifié que le créateur
     * n'a pas de groupe sur cette séance, que les encadrants et adhérents sont éligibles et que les adhérents tiennent
     * dans `$capacity`.
     *
     * @param array{row: array<string, mixed>, title: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, monday: \DateTimeImmutable, occ: string} $session
     * @param list<User> $coaches autres encadrants (le créateur est ajouté d'office)
     * @param list<User> $members
     */
    public function create(
        User $creator,
        ?string $name,
        ?string $info,
        array $coaches,
        array $members,
        array $session,
        int $capacity,
        ?int $paceLevel,
        ?string $speed,
    ): TrainingGroup {
        $this->purgeOld();

        $now = new \DateTimeImmutable();
        $row = $session['row'];
        $group = new TrainingGroup($creator, $this->expiryFor($session, $now));
        $group->setName($name)->setInfo($info)->setSession($session['title'], $session['startsAt'])
            ->setCapacity($capacity)->setPaceLevel($paceLevel)->setSpeed($speed);

        $template = $row['templateId'] !== null ? $this->em->getReference(TrainingSlotTemplate::class, $row['templateId']) : null;
        $slot = $row['templateId'] === null ? $this->em->getReference(TrainingSlot::class, $row['id']) : null;
        $group->attachToSession($template, $slot, $session['monday'], (string) $row['sport']);

        foreach ($coaches as $coach) {
            $group->addCoach($coach);
        }
        foreach ($members as $member) {
            $group->addMember($member);
        }
        $this->em->persist($group);
        $this->em->flush();

        $this->notify($group, $coaches, '%s vous a ajouté comme encadrant de son groupe.', $creator);
        $this->notify($group, $members, '%s vous a ajouté à son groupe d\'entraînement.', $creator);

        return $group;
    }

    /** Les groupes actifs adossés à la même séance que `$group` (lui compris). */
    public function groupsOnSameSession(TrainingGroup $group): array
    {
        $monday = $group->getWeekStartsAt() ?? new \DateTimeImmutable('today');

        return $this->groups->findActiveOnOccurrence($group->getSlotTemplate()?->getId(), $group->getSlot()?->getId(), $monday, new \DateTimeImmutable());
    }

    /**
     * Adhérents (parmi `$users`) déjà dans un AUTRE groupe de la même séance : un adhérent n'en choisit qu'un par séance.
     *
     * @param list<User> $users
     *
     * @return list<array{user: User, group: TrainingGroup}>
     */
    public function memberConflicts(array $users, ?int $templateId, ?int $slotId, \DateTimeImmutable $monday, ?TrainingGroup $except): array
    {
        $conflicts = [];
        foreach ($this->groups->findActiveOnOccurrence($templateId, $slotId, $monday, new \DateTimeImmutable()) as $other) {
            if ($except !== null && $other->getId() === $except->getId()) {
                continue;
            }
            foreach ($users as $user) {
                if ($other->hasMember($user)) {
                    $conflicts[] = ['user' => $user, 'group' => $other];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Un adhérent choisit ce groupe pour la séance (il quitte alors celui qu'il avait choisi pour la même séance).
     * Refusé si le groupe est complet ou si la séance a commencé. Renvoie null en cas de succès, sinon le refus :
     * `['code' => …, 'message' => …, 'status' => HTTP]`.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public function join(TrainingGroup $group, User $user): ?array
    {
        $now = new \DateTimeImmutable();
        if (!$group->isActive($now) || !$group->isAttached()) {
            return self::refusal('closed', 'Ce groupe n\'est plus ouvert aux inscriptions.', 409);
        }
        if ($group->hasStarted($now)) {
            return self::refusal('closed', 'La séance a commencé : le choix du groupe est clos.', 409);
        }
        if (!$user->isActive() || !$user->isAdherent() || $group->hasCoach($user)) {
            return self::refusal('forbidden', 'Vous ne pouvez pas rejoindre ce groupe.', 403);
        }

        // Transaction + verrou sur le groupe : deux adhérents qui choisissent la dernière place en même temps ne la
        // prennent pas tous les deux.
        return $this->em->wrapInTransaction(function () use ($group, $user): ?array {
            $this->em->lock($group, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($group);

            if ($group->hasMember($user)) {
                return null; // déjà dans ce groupe
            }
            if ($group->isFull()) {
                return self::refusal('full', 'Ce groupe est complet.', 409);
            }

            foreach ($this->groupsOnSameSession($group) as $other) {
                if ($other->getId() !== $group->getId() && $other->hasMember($user)) {
                    $other->removeMember($user);
                }
            }
            $group->addMember($user);
            $this->em->flush();

            return null;
        });
    }

    /**
     * Un adhérent quitte le groupe qu'il avait choisi, tant que la séance n'a pas commencé.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public function leaveAsMember(TrainingGroup $group, User $user): ?array
    {
        if (!$group->hasMember($user)) {
            return null;
        }
        if ($group->hasStarted()) {
            return self::refusal('closed', 'La séance a commencé : demandez à l\'encadrant de vous retirer du groupe.', 409);
        }
        $group->removeMember($user);
        $this->em->flush();

        return null;
    }

    /**
     * Remplace la liste des adhérents ; les personnes ajoutées sont prévenues.
     *
     * @param list<User> $members
     */
    public function setMembers(TrainingGroup $group, array $members, User $by): void
    {
        $wantedIds = array_map(static fn (User $u) => $u->getId(), $members);
        $added = [];
        foreach ($members as $member) {
            if (!$group->hasMember($member)) {
                $group->addMember($member);
                $added[] = $member;
            }
        }
        foreach ($group->getMembers()->toArray() as $current) {
            if (!in_array($current->getId(), $wantedIds, true)) {
                $group->removeMember($current);
            }
        }
        $this->em->flush();

        $this->notify($group, $added, '%s vous a ajouté à son groupe d\'entraînement.', $by);
    }

    /**
     * Remplace la liste des autres encadrants (celui qui agit reste encadrant) ; les personnes ajoutées sont prévenues.
     *
     * @param list<User> $coaches
     */
    public function setCoaches(TrainingGroup $group, array $coaches, User $by): void
    {
        $wantedIds = array_map(static fn (User $u) => $u->getId(), $coaches);
        $wantedIds[] = $by->getId();
        $added = [];
        foreach ($coaches as $coach) {
            if (!$group->hasCoach($coach)) {
                $group->addCoach($coach);
                $added[] = $coach;
            }
        }
        foreach ($group->getCoaches()->toArray() as $current) {
            if (!in_array($current->getId(), $wantedIds, true)) {
                $group->removeCoach($current);
            }
        }
        $this->em->flush();

        $this->notify($group, $added, '%s vous a ajouté comme encadrant de son groupe.', $by);
    }

    /** Supprime les groupes terminés depuis plus d'une semaine, avec leurs fichiers. Appelé à chaque création. */
    public function purgeOld(): void
    {
        $limit = (new \DateTimeImmutable())->modify(self::PURGE_AFTER);
        foreach ($this->groups->findFinishedBefore($limit) as $old) {
            $this->files->removeFiles($old);
            $this->em->remove($old);
        }
        $this->em->flush();
    }

    /** @return array{code: string, message: string, status: int} */
    private static function refusal(string $code, string $message, int $status): array
    {
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }

    /**
     * Notification push (best-effort) aux personnes ajoutées. Le texte ne contient que le nom de l'encadrant.
     *
     * @param list<User> $users
     */
    private function notify(TrainingGroup $group, array $users, string $bodyFormat, User $by): void
    {
        $ids = [];
        foreach ($users as $u) {
            if ($u->getId() !== null && $u->getId() !== $by->getId()) {
                $ids[] = (int) $u->getId();
            }
        }
        if ($ids === []) {
            return;
        }
        try {
            $this->push->notifyUsers(
                $ids,
                'Groupe d\'entraînement',
                sprintf($bodyFormat, $by->getFullName()),
                '/training-group/'.$group->getId(),
                'training-group-'.$group->getId(),
            );
        } catch (\Throwable) {
            // Les notifications ne doivent jamais empêcher la création ou la modification du groupe.
        }
    }
}
