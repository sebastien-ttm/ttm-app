<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:mailer:test',
    description: 'Envoie un e-mail de test pour valider la config SMTP (synchrone, bypass Messenger).',
)]
class MailerTestCommand extends Command
{
    public function __construct(private readonly MailerInterface $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Adresse e-mail destinataire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('to');

        $email = (new Email())
            ->to($to)
            ->subject('Test SMTP — Triathlon Toulouse Métropole')
            ->text("Si vous lisez ceci, le SMTP est correctement configuré.\n\nEnvoyé via la commande app:mailer:test.");

        try {
            $this->mailer->send($email);
            $io->success("E-mail de test envoyé à $to (vérifiez aussi les spams).");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Échec : '.$e->getMessage());
            if ($e->getPrevious()) {
                $io->writeln('Cause : '.$e->getPrevious()->getMessage());
            }
            return Command::FAILURE;
        }
    }
}
