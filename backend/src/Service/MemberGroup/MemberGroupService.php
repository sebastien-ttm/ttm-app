<?php

namespace App\Service\MemberGroup;

use App\Entity\Event;
use App\Entity\MemberGroup;
use App\Entity\MemberGroupMember;
use App\Entity\TrainingSeason;
use App\Entity\User;
use App\Repository\MemberGroupMemberRepository;
use App\Repository\MemberGroupRepository;
use App\Repository\TrainingSeasonRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'entrée unique pour créer / peupler / vider les groupes
 * d'adhérents (MemberGroup). Utilisé à la fois par le CRUD admin, les
 * hooks de vote de présence et les hooks de réponse de sondage.
 *
 * Les upserts vont chercher / créer un groupe pour une saison + nom.
 * Sans saison active configurée, on tombe sur findOrCreate() pour ne
 * pas bloquer l'appelant — l'admin peut renommer la saison ensuite.
 */
class MemberGroupService
{
    /**
     * Cache in-request des groupes déjà résolus / créés pendant la
     * même requête HTTP. Évite le double-persist quand plusieurs
     * questions d'un même sondage ciblent le même nom de groupe :
     * findBy ne « voit » pas les entités persistées mais pas encore
     * flushées, donc on tomberait dans le path création deux fois.
     *
     * Clé : "surveyName:{trim($name)}" ou "event:{eventId}".
     *
     * @var array<string, MemberGroup>
     */
    private array $requestCache = [];

    public function __construct(
        private readonly MemberGroupRepository $groups,
        private readonly MemberGroupMemberRepository $memberships,
        private readonly TrainingSeasonRepository $seasons,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Récupère (ou crée) le groupe lié à un événement à vote.
     * Nommage par défaut : titre de l'événement (peut être renommé
     * ensuite dans l'admin sans casser la liaison — on retrouve le
     * groupe via sourceEvent).
     */
    public function ensureGroupForEvent(Event $event): MemberGroup
    {
        $key = 'event:'.($event->getId() ?? spl_object_hash($event));
        if (isset($this->requestCache[$key])) {
            return $this->requestCache[$key];
        }
        $existing = $this->groups->findOneByEvent($event);
        if ($existing !== null) {
            return $this->requestCache[$key] = $existing;
        }
        $season = $this->seasonForDate($event->getStartsAt());
        $name = $event->getTitle() !== '' ? $event->getTitle() : ('Événement #'.$event->getId());
        // Si un groupe manuel existe déjà avec ce nom sur la saison,
        // on le rattache à l'événement plutôt que d'échouer sur la
        // contrainte d'unicité (season, name).
        $bySeasonName = $this->groups->findOneBySeasonAndName($season, $name);
        if ($bySeasonName !== null) {
            $bySeasonName->setSourceEvent($event);
            $bySeasonName->setSource(MemberGroup::SOURCE_EVENT);
            return $this->requestCache[$key] = $bySeasonName;
        }
        $group = (new MemberGroup())
            ->setSeason($season)
            ->setName($name)
            ->setSource(MemberGroup::SOURCE_EVENT)
            ->setSourceEvent($event);
        $this->em->persist($group);
        return $this->requestCache[$key] = $group;
    }

    /**
     * Récupère (ou crée) le groupe pour une réponse de sondage.
     * `question` est purement descriptif — sert de trace pour l'admin
     * (audit / debug). Le rattachement effectif se fait par (season,
     * name) : plusieurs questions peuvent pointer vers le même groupe.
     */
    public function ensureGroupForSurvey(string $name, string $question): MemberGroup
    {
        $key = 'surveyName:'.trim($name);
        if (isset($this->requestCache[$key])) {
            return $this->requestCache[$key];
        }
        $season = $this->seasonForDate(new \DateTimeImmutable('today'));
        $group = $this->groups->findOneBySeasonAndName($season, $name);
        if ($group !== null) {
            return $this->requestCache[$key] = $group;
        }
        $group = (new MemberGroup())
            ->setSeason($season)
            ->setName($name)
            ->setSource(MemberGroup::SOURCE_SURVEY)
            ->setSourceSurveyQuestion(mb_substr($question, 0, 100));
        $this->em->persist($group);
        return $this->requestCache[$key] = $group;
    }

    /**
     * Ajoute un user dans un groupe si ce n'est pas déjà fait.
     * Retourne true si une nouvelle ligne a été créée, false si
     * l'appartenance existait déjà. Ne flush pas — l'appelant décide.
     */
    public function addMember(MemberGroup $group, User $user, string $source = MemberGroupMember::SOURCE_MANUAL): bool
    {
        if ($group->getId() !== null) {
            $existing = $this->memberships->findOneByGroupAndUser($group, $user);
            if ($existing !== null) {
                return false;
            }
        }
        // Défense supplémentaire : quand le groupe vient d'être
        // persisté (id encore null) et que addMember est appelé plusieurs
        // fois pour la même paire (group, user) avant le flush — cas
        // possible si un sondage a plusieurs questions ciblant le même
        // groupe. `findBy` ne voit pas les entités en attente d'insert,
        // on interroge donc l'UnitOfWork.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof MemberGroupMember
                && $pending->getGroup() === $group
                && $pending->getUser()->getId() === $user->getId()
            ) {
                return false;
            }
        }
        $m = new MemberGroupMember($group, $user, $source);
        $this->em->persist($m);
        return true;
    }

    /**
     * Retire un user d'un groupe. No-op s'il n'y était pas.
     */
    public function removeMember(MemberGroup $group, User $user): void
    {
        $existing = $this->memberships->findOneByGroupAndUser($group, $user);
        if ($existing !== null) {
            $this->em->remove($existing);
        }
    }

    /**
     * Parcourt le schéma d'un sondage pour toutes les questions déclarant
     * un bloc `groupTarget` ({name, trigger}) ; si la réponse soumise
     * matche le trigger, ajoute le user au groupe cible (créé au besoin,
     * saison courante). Sinon, le retire — un membre qui change sa
     * réponse sort automatiquement du groupe. Aucun flush ici : l'appelant
     * flush après (appelé en boucle depuis submit() et depuis le backfill
     * admin sur toutes les réponses existantes).
     *
     * @param list<array<string, mixed>> $sections
     * @param array<string, mixed>       $answers
     */
    public function syncSurveyGroupTargets(array $sections, array $answers, User $user): void
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

            $group = $this->ensureGroupForSurvey($name, (string) ($q['label'] ?? $qid));
            if ($matches) {
                $this->addMember($group, $user, MemberGroupMember::SOURCE_SURVEY_ANSWER);
            } else {
                $this->removeMember($group, $user);
            }
        }
    }

    /**
     * Détermine la saison à utiliser pour rattacher un groupe :
     *  - la saison qui contient $date si elle est configurée,
     *  - sinon la saison courante (findOrCreate côté repo).
     */
    private function seasonForDate(\DateTimeInterface $date): TrainingSeason
    {
        $season = $this->seasons->findCurrent(\DateTimeImmutable::createFromInterface($date));
        return $season ?? $this->seasons->findOrCreate();
    }
}
