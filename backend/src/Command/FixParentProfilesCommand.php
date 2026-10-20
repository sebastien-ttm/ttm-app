<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ajoute le profil Parent aux comptes qui ont des enfants rattachés (relation parent → enfant déclarée dans l'appli ou
 * dans l'admin) et ne l'ont pas. Liste aussi, sans les modifier, les comptes « principaux » d'un e-mail partagé qui n'ont
 * pas le profil Parent : ce lien-là (« Rattaché à ») n'est pas une déclaration d'enfant, à traiter au cas par cas.
 *
 *   php bin/console app:users:fix-parent-profiles [--dry-run]
 */
#[AsCommand(
    name: 'app:users:fix-parent-profiles',
    description: 'Ajoute le profil Parent aux comptes ayant des enfants rattachés et liste les cas à vérifier.',
)]
class FixParentProfilesCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait fait, sans rien modifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var list<User> $withChildren */
        $withChildren = $this->users->createQueryBuilder('u')
            ->where('SIZE(u.children) > 0')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        $io->section(sprintf('Comptes avec des enfants rattachés : %d', count($withChildren)));
        $fixed = [];
        foreach ($withChildren as $user) {
            if ($user->isParent()) {
                continue;
            }
            $fixed[] = [
                $user->getId(),
                $user->getFullName(),
                $user->getEmail(),
                implode(', ', array_map(static fn (User $c) => $c->getFullName(), $user->getChildren()->toArray())),
                implode(', ', $user->getProfiles()) ?: '(aucun)',
            ];
            if (!$dryRun) {
                $user->ensureParentProfile();
            }
        }
        if ($fixed === []) {
            $io->success('Tous les comptes qui ont des enfants rattachés ont déjà le profil Parent.');
        } else {
            $io->table(['ID', 'Compte', 'E-mail', 'Enfants', 'Profils avant'], $fixed);
            $io->success(sprintf('%d compte(s) %s.', count($fixed), $dryRun ? 'recevraient le profil Parent (--dry-run : rien modifié)' : 'ont reçu le profil Parent'));
        }

        // Comptes principaux d'un e-mail partagé (dépendants « Rattaché à ») sans profil Parent : information seulement.
        /** @var list<User> $primaries */
        $primaries = $this->users->createQueryBuilder('u')
            ->where('SIZE(u.dependents) > 0')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
        $shared = [];
        foreach ($primaries as $user) {
            if ($user->isParent()) {
                continue;
            }
            $shared[] = [
                $user->getId(),
                $user->getFullName(),
                $user->getEmail(),
                implode(', ', array_map(static fn (User $d) => $d->getFullName(), $user->getDependents()->toArray())),
            ];
        }
        if ($shared !== []) {
            $io->section('À vérifier : comptes principaux d\'un e-mail partagé, sans profil Parent (non modifiés)');
            $io->table(['ID', 'Compte', 'E-mail', 'Profils rattachés (même e-mail)'], $shared);
            $io->note('Ce lien vient du partage d\'un même e-mail, pas d\'une déclaration d\'enfant : cochez le profil Parent à la main (admin) si ce sont bien des enfants.');
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return Command::SUCCESS;
    }
}
