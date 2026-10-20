<?php

namespace App\Service\TrainingGroup;

use App\Entity\TrainingGroup;
use App\Entity\User;
use App\Repository\TrainingGroupRepository;
use App\Service\Training\WeeklyScheduleService;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cycle de vie des groupes d'entraînement « à la volée » : calcul de leur durée de validité d'après le planning,
 * création, gestion des encadrants et des adhérents (avec notification des personnes ajoutées), nettoyage.
 */
class TrainingGroupService
{
    /** Un groupe expire au plus tard 10 jours après sa création, même si le planning n'a rien d'autre à proposer. */
    private const MAX_LIFETIME = '+10 days';
    /** Sans planning exploitable : le groupe vit une demi-journée. */
    private const FALLBACK_LIFETIME = '+12 hours';
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
     * Séance de référence et fin de validité d'un groupe créé à l'instant `$now`.
     *
     * La séance de référence est celle en cours ou, à défaut, la prochaine (créneaux non annulés du planning). Le groupe
     * « reste jusqu'au prochain créneau » : il expire au début du créneau qui suit la séance de référence — sans
     * jamais finir avant la fin de cette séance.
     *
     * @return array{session: ?array{title: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}, expiresAt: \DateTimeImmutable}
     */
    public function plan(\DateTimeImmutable $now): array
    {
        $slots = [];
        $firstMonday = WeeklyScheduleService::snapToMonday($now->modify('-1 day'));
        for ($week = 0; $week < 3; ++$week) {
            foreach ($this->schedule->buildWeek($firstMonday->modify('+'.$week.' weeks')) as $row) {
                if ($row['isCancelled']) {
                    continue;
                }
                $start = new \DateTimeImmutable($row['date'].' '.$row['startTime']);
                $slots[] = [
                    'title' => (string) $row['title'],
                    'startsAt' => $start,
                    'endsAt' => $start->modify('+'.(int) $row['durationMinutes'].' minutes'),
                ];
            }
        }
        usort($slots, static fn (array $a, array $b) => $a['startsAt'] <=> $b['startsAt']);

        $reference = null;
        foreach ($slots as $slot) {
            if ($slot['endsAt'] > $now) {
                $reference = $slot;
                break;
            }
        }
        if ($reference === null) {
            return ['session' => null, 'expiresAt' => $now->modify(self::FALLBACK_LIFETIME)];
        }

        $expiresAt = $reference['endsAt'];
        foreach ($slots as $slot) {
            if ($slot['startsAt'] > $reference['startsAt']) {
                if ($slot['startsAt'] > $expiresAt) {
                    $expiresAt = $slot['startsAt'];
                }
                break;
            }
        }
        $cap = $now->modify(self::MAX_LIFETIME);

        return ['session' => $reference, 'expiresAt' => $expiresAt < $cap ? $expiresAt : $cap];
    }

    /**
     * Crée et enregistre un groupe. L'appelant a déjà vérifié que le créateur n'a pas de groupe actif et que les
     * encadrants et adhérents sont éligibles.
     *
     * @param list<User> $coaches  autres encadrants (le créateur est ajouté d'office)
     * @param list<User> $members
     */
    public function create(User $creator, ?string $name, ?string $info, array $coaches, array $members): TrainingGroup
    {
        $this->purgeOld();

        $plan = $this->plan(new \DateTimeImmutable());
        $group = new TrainingGroup($creator, $plan['expiresAt']);
        $group->setName($name)->setInfo($info)->setSession($plan['session']['title'] ?? null, $plan['session']['startsAt'] ?? null);
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
