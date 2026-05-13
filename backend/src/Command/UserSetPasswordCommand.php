<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:set-password',
    description: 'Définit ou réinitialise le mot de passe d\'un utilisateur (recherché par email).',
)]
class UserSetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email du compte à modifier')
            ->addArgument('password', InputArgument::REQUIRED, 'Nouveau mot de passe en clair (sera hashé)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        if (mb_strlen($password) < 8) {
            $io->error('Le mot de passe doit faire au moins 8 caractères.');
            return Command::FAILURE;
        }

        $user = $this->users->findOneByEmail($email);
        if ($user === null) {
            $io->error("Aucun utilisateur trouvé avec l'email: $email");
            return Command::FAILURE;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();

        $io->success(sprintf(
            'Mot de passe défini pour %s (%s).',
            $user->getFullName(),
            $email,
        ));
        return Command::SUCCESS;
    }
}
