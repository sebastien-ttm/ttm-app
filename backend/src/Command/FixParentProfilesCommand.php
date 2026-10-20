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
 * Ajoute le profil Parent aux comptes qui le sont de fait et ne l'ont pas :
 *  - ceux qui ont des enfants rattachés (relation parent → enfant, déclarée dans l'appli ou dans l'admin) ;
 *  - les comptes principaux d'un e-mail partagé (ceux auxquels d'autres profils sont « rattachés »).
 * Désormais ces deux cas reçoivent le profil automatiquement (User::addChild(), User::setLinkedToUser(), import CSV,
 * admin) ; cette commande rattrape les comptes existants et affiche ce qu'elle a changé.
 *
 *   php bin/console app:users:fix-parent-profiles [--dry-run]
 */
#[AsCommand(
    name: 'app:users:fix-parent-profiles',
    description: 'Ajoute le profil Parent aux comptes ayant des enfants rattachés ou principaux d\'un e-mail partagé.',
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

        $this->fix(
            $io,
            $dryRun,
            'Comptes avec des enfants rattachés',
            'SIZE(u.children) > 0',
            'Enfants',
            static fn (User $u) => $u->getChildren()->toArray(),
        );
        $this->fix(
            $io,
            $dryRun,
            'Comptes principaux d\'un e-mail partagé',
            'SIZE(u.dependents) > 0',
            'Profils rattachés (même e-mail)',
            static fn (User $u) => $u->getDependents()->toArray(),
        );

        if (!$dryRun) {
            $this->em->flush();
        }

        return Command::SUCCESS;
    }

    /**
     * @param callable(User): list<User> $related
     */
    private function fix(SymfonyStyle $io, bool $dryRun, string $title, string $condition, string $relatedLabel, callable $related): void
    {
        /** @var list<User> $candidates */
        $candidates = $this->users->createQueryBuilder('u')
            ->where($condition)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        $io->section(sprintf('%s : %d', $title, count($candidates)));
        $rows = [];
        foreach ($candidates as $user) {
            if ($user->isParent()) {
                continue;
            }
            $rows[] = [
                $user->getId(),
                $user->getFullName(),
                $user->getEmail(),
                implode(', ', array_map(static fn (User $r) => $r->getFullName(), $related($user))),
                implode(', ', $user->getProfiles()) ?: '(aucun)',
            ];
            if (!$dryRun) {
                $user->ensureParentProfile();
            }
        }

        if ($rows === []) {
            $io->success('Tous ces comptes ont déjà le profil Parent.');
            return;
        }
        $io->table(['ID', 'Compte', 'E-mail', $relatedLabel, 'Profils avant'], $rows);
        $io->success(sprintf('%d compte(s) %s.', count($rows), $dryRun ? 'recevraient le profil Parent (--dry-run : rien modifié)' : 'ont reçu le profil Parent'));
    }
}
