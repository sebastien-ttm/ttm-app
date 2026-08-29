<?php

namespace App\Command;

use App\Entity\UserSeasonMembership;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Repository\UserSeasonMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill : marque tous les adhérents actuellement en base (importés via
 * un CSV FFTri à un moment donné) comme membres d'une saison donnée.
 *
 * Utile pour combler l'historique avant l'introduction du selecteur de
 * saison à l'import : les adhérents 2025-2026 n'ont pas de UserSeasonMembership
 * puisque la feature n'existait pas quand ils ont été importés.
 *
 * Sélection : tous les users avec lastCsvSyncAt IS NOT NULL (ils ont été
 * vus au moins une fois dans un import CSV). Idempotent : upsert par
 * (user, saison), pas de doublon si déjà présent.
 */
#[AsCommand(
    name: 'app:memberships:backfill',
    description: 'Marque les adhérents déjà importés comme membres d\'une saison donnée.',
)]
class BackfillSeasonMembershipsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('season', 's', InputOption::VALUE_REQUIRED, 'ID de la saison cible')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait fait sans écrire')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Passe outre la confirmation interactive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seasonId = $input->getOption('season');
        if ($seasonId === null) {
            $io->error('Option --season=<id> requise. Liste des saisons via l\'admin ou :');
            $io->text('  SELECT id, name, starts_at, ends_at FROM training_season ORDER BY starts_at DESC;');
            return Command::FAILURE;
        }
        $season = $this->seasons->find((int) $seasonId);
        if ($season === null) {
            $io->error('Saison introuvable pour id='.$seasonId);
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $candidates = $this->users->createQueryBuilder('u')
            ->where('u.lastCsvSyncAt IS NOT NULL')
            ->orderBy('u.nom', 'ASC')
            ->getQuery()->getResult();

        $total = count($candidates);
        if ($total === 0) {
            $io->warning('Aucun adhérent avec lastCsvSyncAt renseigné — rien à backfiller.');
            return Command::SUCCESS;
        }

        $io->title(sprintf(
            '%s — Backfill saison « %s » (id=%d)',
            $dryRun ? 'DRY-RUN' : 'BACKFILL',
            (string) $season,
            $season->getId(),
        ));
        $io->text(sprintf('%d adhérent(s) candidat(s) (lastCsvSyncAt renseigné).', $total));

        if (!$dryRun && !$force) {
            if (!$io->confirm('Créer un UserSeasonMembership pour chacun d\'eux ?', false)) {
                $io->warning('Annulé.');
                return Command::SUCCESS;
            }
        }

        $created = 0;
        $skipped = 0;
        foreach ($candidates as $user) {
            $existing = $this->memberships->findOneByUserAndSeason($user, $season);
            if ($existing !== null) {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $m = new UserSeasonMembership($user, $season);
                // Snapshots depuis les champs déjà normalisés en base — pas
                // de CSV disponible ici. Vaut mieux "" que rien pour retrouver
                // le type de licence courant.
                $m->setStatutLicence($user->getStatutLicence() ?: null);
                $m->setTypeLicence($user->getTypeLicence() ?: null);
                $m->setCategorieAge($user->getCategorieAge() ?: null);
                $this->em->persist($m);
            }
            $created++;
        }
        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s — %d membership(s) %s, %d déjà existant(s) (skipped).',
            $dryRun ? 'DRY-RUN terminé' : 'Backfill terminé',
            $created,
            $dryRun ? 'seraient créés' : 'créés',
            $skipped,
        ));
        return Command::SUCCESS;
    }
}
