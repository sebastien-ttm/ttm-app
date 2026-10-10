<?php

namespace App\Command;

use App\Repository\MagicLinkTokenRepository;
use App\Repository\PasswordResetTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tokens:cleanup', description: 'Supprime les magic link tokens et les jetons de réinitialisation de mot de passe expirés ou consommés depuis plus de 7 jours.')]
class TokensCleanupCommand extends Command
{
    public function __construct(
        private readonly MagicLinkTokenRepository $tokens,
        private readonly PasswordResetTokenRepository $passwordResetTokens,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->tokens->deleteExpired();
        $output->writeln("$deleted token(s) de connexion supprimé(s).");
        $deleted = $this->passwordResetTokens->deleteExpired();
        $output->writeln("$deleted jeton(s) de réinitialisation de mot de passe supprimé(s).");
        return Command::SUCCESS;
    }
}
