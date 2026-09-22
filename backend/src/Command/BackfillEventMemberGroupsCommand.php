<?php

namespace App\Command;

use App\Entity\Event;
use App\Entity\MemberGroupMember;
use App\Enum\AttendanceStatus;
use App\Repository\EventAttendanceRepository;
use App\Repository\EventRepository;
use App\Service\MemberGroup\MemberGroupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill : crée (ou complète) le MemberGroup de chaque événement
 * « soumis au vote de présence » ayant déjà des votes en base.
 *
 * Le rattachement événement → groupe (MemberGroupService::ensureGroupForEvent)
 * n'a été introduit qu'après la fonctionnalité de vote elle-même — les
 * votes déposés avant ce déploiement n'ont donc jamais déclenché la
 * création du groupe ni l'ajout des « oui » comme membres. Cette
 * commande comble l'écart une fois pour toutes.
 *
 * Idempotent : addMember() ignore les adhésions déjà existantes,
 * ensureGroupForEvent() réutilise le groupe si déjà créé (par ex. par
 * un vote déposé depuis le déploiement).
 */
#[AsCommand(
    name: 'app:member-groups:backfill-events',
    description: 'Crée les groupes manquants pour les événements à vote déjà votés.',
)]
class BackfillEventMemberGroupsCommand extends Command
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventAttendanceRepository $attendances,
        private readonly MemberGroupService $memberGroups,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait fait sans écrire')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Passe outre la confirmation interactive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        /** @var list<Event> $votableEvents */
        $votableEvents = $this->events->createQueryBuilder('e')
            ->where('e.voteEnabled = true')
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        if ($votableEvents === []) {
            $io->warning('Aucun événement soumis au vote — rien à backfiller.');
            return Command::SUCCESS;
        }

        $io->title(($dryRun ? 'DRY-RUN' : 'BACKFILL').' — groupes d\'adhérents manquants pour événements votés');
        $io->text(sprintf('%d événement(s) à vote à examiner.', count($votableEvents)));

        if (!$dryRun && !$force) {
            if (!$io->confirm('Créer les groupes manquants et y ajouter les votants « oui » ?', false)) {
                $io->warning('Annulé.');
                return Command::SUCCESS;
            }
        }

        $groupsTouched = 0;
        $membersAdded = 0;
        $eventsSkipped = 0;

        foreach ($votableEvents as $event) {
            $votes = $this->attendances->findByEventWithUser($event);
            if ($votes === []) {
                $eventsSkipped++;
                continue;
            }

            $group = $dryRun ? null : $this->memberGroups->ensureGroupForEvent($event);
            $groupsTouched++;

            foreach ($votes as $vote) {
                if ($vote->getStatus() !== AttendanceStatus::Yes) {
                    continue;
                }
                if ($dryRun) {
                    $membersAdded++;
                    continue;
                }
                if ($this->memberGroups->addMember($group, $vote->getUser(), MemberGroupMember::SOURCE_EVENT_VOTE)) {
                    $membersAdded++;
                }
            }

            $io->text(sprintf(
                '  · %s (%s) : %d vote(s)',
                $event->getTitle(),
                $event->getStartsAt()->format('d/m/Y'),
                count($votes),
            ));
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s — %d groupe(s) touché(s), %d adhésion(s) %s, %d événement(s) sans vote ignoré(s).',
            $dryRun ? 'DRY-RUN terminé' : 'Backfill terminé',
            $groupsTouched,
            $membersAdded,
            $dryRun ? 'seraient ajoutées' : 'ajoutées',
            $eventsSkipped,
        ));
        return Command::SUCCESS;
    }
}
