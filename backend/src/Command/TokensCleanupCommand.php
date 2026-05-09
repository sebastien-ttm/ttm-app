<?php

namespace App\Command;

use App\Repository\MagicLinkTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tokens:cleanup', description: 'Supprime les magic link tokens expirés ou consommés depuis plus de 7 jours.')]
class TokensCleanupCommand extends Command
{
    public function __construct(private readonly MagicLinkTokenRepository $tokens)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->tokens->deleteExpired();
        $output->writeln("$deleted token(s) supprimé(s).");
        return Command::SUCCESS;
    }
}
