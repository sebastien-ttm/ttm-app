<?php

namespace App\Command;

use App\Service\Piwigo\PiwigoClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:piwigo:purge-cache', description: 'Supprime du cache disque les photos Piwigo non consultées depuis N jours.')]
class PiwigoCachePurgeCommand extends Command
{
    public function __construct(private readonly PiwigoClient $piwigo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Nombre de jours sans consultation au-delà duquel une photo est supprimée du cache.',
            '30',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $output->writeln('<error>--days doit être au moins 1.</error>');
            return Command::INVALID;
        }

        $result = $this->piwigo->purgeCache($days);
        $output->writeln(sprintf(
            '%d fichier(s) supprimé(s) du cache Piwigo (%.1f Mo libérés).',
            $result['files'],
            $result['bytes'] / 1_048_576,
        ));

        return Command::SUCCESS;
    }
}
