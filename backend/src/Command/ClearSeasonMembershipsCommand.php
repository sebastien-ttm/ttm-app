<?php

namespace App\Command;

use App\Repository\TrainingSeasonRepository;
use App\Repository\UserSeasonMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Détache tous les adhérents d'une saison — utile pour corriger un
 * mauvais import CSV. Ne supprime PAS les comptes User, seulement les
 * lignes UserSeasonMembership pour la saison indiquée.
 *
 *   php bin/console app:memberships:clear-season --season=<id> [--dry-run]
 */
#[AsCommand(
    name: 'app:memberships:clear-season',
    description: 'Détache tous les adhérents d\'une saison (supprime les UserSeasonMembership).',
)]
class ClearSeasonMembershipsCommand extends Command
{
    public function __construct(
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('season', 's', InputOption::VALUE_REQUIRED, 'ID de la saison à nettoyer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche sans supprimer')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Passe outre la confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seasonId = $input->getOption('season');
        if ($seasonId === null) {
            $io->error('Option --season=<id> requise.');
            return Command::FAILURE;
        }
        $season = $this->seasons->find((int) $seasonId);
        if ($season === null) {
            $io->error('Saison introuvable pour id='.$seasonId);
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $rows = $this->memberships->findBySeason($season);
        $total = count($rows);
        if ($total === 0) {
            $io->warning(sprintf('Aucun adhérent attaché à la saison « %s ». Rien à faire.', (string) $season));
            return Command::SUCCESS;
        }

        $io->title(sprintf(
            '%s — Détacher les adhérents de la saison « %s » (id=%d)',
            $dryRun ? 'DRY-RUN' : 'SUPPRESSION',
            (string) $season,
            $season->getId(),
        ));
        $io->text(sprintf('%d adhésion(s) à supprimer.', $total));

        if ($output->isVerbose() || $dryRun) {
            $io->section('Aperçu (10 premiers)');
            foreach (array_slice($rows, 0, 10) as $m) {
                $io->writeln(sprintf('  · %s', $m->getUser()->getFullName()));
            }
            if ($total > 10) {
                $io->writeln(sprintf('  … et %d de plus', $total - 10));
            }
        }

        if (!$dryRun && !$force) {
            if (!$io->confirm(sprintf('Supprimer les %d liaison(s) ? (Les comptes User restent, seul le lien à cette saison est retiré)', $total), false)) {
                $io->warning('Annulé.');
                return Command::SUCCESS;
            }
        }

        if ($dryRun) {
            $io->success(sprintf('DRY-RUN terminé — %d adhésion(s) seraient supprimées.', $total));
            return Command::SUCCESS;
        }

        foreach ($rows as $m) {
            $this->em->remove($m);
        }
        $this->em->flush();

        $io->success(sprintf('%d adhésion(s) supprimée(s). Les comptes User sont intacts.', $total));
        return Command::SUCCESS;
    }
}
