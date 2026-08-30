<?php

namespace App\Command;

use App\Repository\TrainingSeasonRepository;
use App\Service\Csv\CsvImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:csv:import', description: 'Importe un fichier CSV d\'adhérents (Espace Tri).')]
class CsvImportCommand extends Command
{
    public function __construct(
        private readonly CsvImportService $importer,
        private readonly TrainingSeasonRepository $seasons,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin vers le fichier CSV')
            ->addOption('delimiter', 'd', InputOption::VALUE_REQUIRED, 'Séparateur', ',')
            ->addOption('no-welcome', null, InputOption::VALUE_NONE, 'Ne pas envoyer d\'e-mail de bienvenue aux nouveaux comptes')
            ->addOption('season', 's', InputOption::VALUE_REQUIRED, 'ID de la saison d\'adhésion (par défaut : saison courante)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : parse le CSV et affiche les compteurs SANS écrire en base ni envoyer d\'emails')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        if (!is_file($file) || !is_readable($file)) {
            $io->error("Fichier introuvable ou illisible : $file");
            return Command::FAILURE;
        }

        // Saison sélectionnée : --season=<id>, sinon la courante, sinon avertit.
        $seasonId = $input->getOption('season');
        $season = $seasonId !== null ? $this->seasons->find((int) $seasonId) : $this->seasons->findCurrent();
        if ($season === null) {
            $io->warning('Aucune saison trouvée : les adhésions ne seront pas tracées par saison. '
                .'Précisez --season=<id> ou créez une saison courante.');
        }

        $io->title('Import CSV adhérents');
        $io->text('Fichier : '.$file);
        if ($season !== null) {
            $io->text('Saison  : '.(string) $season);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $io->note('MODE DRY-RUN — aucune écriture DB, aucun email dispatché.');
        }

        $result = $this->importer->import(
            filePath: $file,
            sendWelcomeEmails: !$input->getOption('no-welcome'),
            delimiter: (string) $input->getOption('delimiter'),
            season: $season,
            dryRun: $dryRun,
        );

        $io->definitionList(
            ['Créés' => $result->created],
            ['Mis à jour' => $result->updated],
            ['Désactivés' => $result->deactivated],
            ['Ignorés' => $result->skipped],
            ['Erreurs' => count($result->errors)],
        );

        if ($result->errors !== []) {
            $io->section('Erreurs');
            foreach ($result->errors as $err) {
                $io->writeln(sprintf('  ligne %d : %s', $err['line'], $err['error']));
            }
        }

        return Command::SUCCESS;
    }
}
