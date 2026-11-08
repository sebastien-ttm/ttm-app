<?php

namespace App\Command;

use App\Entity\VolunteerCampaign;
use App\Entity\VolunteerPole;
use App\Entity\VolunteerRole;
use App\Repository\VolunteerCampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe une campagne de bénévolat (pôles, activités, postes terrain) depuis un fichier JSON, par exemple
 * data/volunteer/breathman-2027.json (issu du classeur « Activités par pôle 2027 »).
 *
 * Idempotent : la campagne est retrouvée par son slug, les pôles par leur nom, les fiches par (pôle, type, nom).
 * Par défaut, seuls les éléments absents sont créés — une fiche déjà en base n'est pas modifiée, l'appli étant
 * désormais la référence. --overwrite réécrit le contenu des fiches existantes (textes, case « publiée », nombre
 * requis, noms saisis à la main), sans jamais toucher aux responsables de pôle ni aux candidatures.
 *
 * La campagne est créée NON publiée : désigner les responsables de pôle dans l'admin, puis la publier.
 */
#[AsCommand(
    name: 'app:volunteer:import',
    description: 'Importe une campagne de bénévolat (pôles, activités, postes terrain) depuis un fichier JSON.',
)]
class VolunteerImportCommand extends Command
{
    public function __construct(
        private readonly VolunteerCampaignRepository $campaigns,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier JSON (ex. data/volunteer/breathman-2027.json)')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Réécrit le contenu des fiches déjà présentes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait fait sans écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('file');
        $overwrite = (bool) $input->getOption('overwrite');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($path)) {
            $io->error(sprintf('Fichier introuvable : %s', $path));
            return Command::FAILURE;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['slug'], $data['name'], $data['startsOn'], $data['poles']) || !is_array($data['poles'])) {
            $io->error('JSON invalide : slug, name, startsOn et poles sont obligatoires.');
            return Command::FAILURE;
        }

        $created = ['pôles' => 0, 'activités' => 0, 'postes terrain' => 0];
        $updated = 0;

        $campaign = $this->campaigns->findOneBySlug((string) $data['slug']);
        if ($campaign === null) {
            $campaign = (new VolunteerCampaign())
                ->setSlug((string) $data['slug'])
                ->setName((string) $data['name'])
                ->setStartsOn($this->date((string) $data['startsOn']) ?? new \DateTimeImmutable('today'))
                ->setEndsOn($this->date($data['endsOn'] ?? null))
                ->setLocation($data['location'] ?? null)
                ->setApplicationsDeadline($this->date($data['applicationsDeadline'] ?? null))
                ->setIntro($data['intro'] ?? null)
                ->setPublished(false)
                ->setApplicationsOpen(true);
            $this->em->persist($campaign);
            $io->text(sprintf('Campagne créée : %s (non publiée)', $campaign->getName()));
        } else {
            $io->text(sprintf('Campagne existante : %s', $campaign->getName()));
        }

        /** @var array<string, VolunteerRole> $activitiesByName */
        $activitiesByName = [];
        /** @var list<array{0: VolunteerRole, 1: string}> $pendingLinks */
        $pendingLinks = [];

        foreach (array_values($data['poles']) as $poleIndex => $poleData) {
            if (!is_array($poleData) || trim((string) ($poleData['name'] ?? '')) === '') {
                continue;
            }
            $pole = $this->findPole($campaign, (string) $poleData['name']);
            if ($pole === null) {
                $pole = (new VolunteerPole($campaign, (string) $poleData['name']))
                    ->setManagerNames($poleData['managerNames'] ?? null)
                    ->setColor((string) ($poleData['color'] ?? 'teal'))
                    ->setPosition($poleIndex);
                $campaign->addPole($pole);
                $this->em->persist($pole);
                $created['pôles']++;
            }

            foreach ([VolunteerRole::KIND_ACTIVITY => 'activities', VolunteerRole::KIND_FIELD => 'fieldRoles'] as $kind => $key) {
                foreach (array_values(is_array($poleData[$key] ?? null) ? $poleData[$key] : []) as $roleIndex => $roleData) {
                    if (!is_array($roleData) || trim((string) ($roleData['name'] ?? '')) === '') {
                        continue;
                    }
                    $role = $this->findRole($pole, $kind, (string) $roleData['name']);
                    $isNew = $role === null;
                    if ($isNew) {
                        $role = new VolunteerRole($pole, $kind, (string) $roleData['name']);
                        $role->setPosition($roleIndex);
                        $pole->addRole($role);
                        $this->em->persist($role);
                        $created[$kind === VolunteerRole::KIND_FIELD ? 'postes terrain' : 'activités']++;
                    }
                    if ($isNew || $overwrite) {
                        $role->setTitle($roleData['title'] ?? null)
                            ->setPitch($roleData['pitch'] ?? null)
                            ->setContent(is_array($roleData['content'] ?? null) ? $roleData['content'] : [])
                            ->setPublished((bool) ($roleData['published'] ?? true))
                            ->setRequiredCount(isset($roleData['requiredCount']) ? (int) $roleData['requiredCount'] : null)
                            ->setManualNames($roleData['manualNames'] ?? null);
                        if (!$isNew) {
                            $updated++;
                        }
                        if ($kind === VolunteerRole::KIND_FIELD && trim((string) ($roleData['activity'] ?? '')) !== '') {
                            $pendingLinks[] = [$role, trim((string) $roleData['activity'])];
                        }
                    }
                    if ($kind === VolunteerRole::KIND_ACTIVITY) {
                        $activitiesByName[$role->getName()] = $role;
                    }
                }
            }
        }

        // Rattachement des postes terrain à leur activité (cherchée dans toute la campagne).
        foreach ($pendingLinks as [$role, $activityName]) {
            $activity = $activitiesByName[$activityName] ?? null;
            if ($activity === null) {
                $io->warning(sprintf('Poste « %s » : activité « %s » introuvable, rattachement ignoré.', $role->getName(), $activityName));
            }
            $role->setActivity($activity);
        }

        $io->listing(array_map(
            static fn (string $k, int $n) => sprintf('%s créé(e)s : %d', ucfirst($k), $n),
            array_keys($created),
            array_values($created),
        ));
        if ($overwrite) {
            $io->text(sprintf('Fiches réécrites : %d', $updated));
        }

        if ($dryRun) {
            $io->note('Simulation : rien n\'a été enregistré.');
            return Command::SUCCESS;
        }
        $this->em->flush();
        $io->success('Import terminé. Désignez les responsables de pôle dans l\'admin, puis publiez la campagne.');

        return Command::SUCCESS;
    }

    private function findPole(VolunteerCampaign $campaign, string $name): ?VolunteerPole
    {
        foreach ($campaign->getPoles() as $pole) {
            if ($pole->getName() === trim($name)) {
                return $pole;
            }
        }
        return null;
    }

    private function findRole(VolunteerPole $pole, string $kind, string $name): ?VolunteerRole
    {
        foreach ($pole->getRoles() as $role) {
            if ($role->getKind() === $kind && $role->getName() === trim($name)) {
                return $role;
            }
        }
        return null;
    }

    private function date(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d !== false ? $d : null;
    }
}
