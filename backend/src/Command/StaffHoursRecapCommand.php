<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Training\StaffHoursRecapService;
use App\Service\Training\WeeklyScheduleService;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Envoie aux responsables RH (rôle backend « Responsable RH ») le récap des heures encadrées par entraîneur sur la
 * semaine en cours : même contenu que le récap de la page « Présence entraînements ». À planifier par une tâche
 * cron chaque samedi soir (voir docs/DEPLOYMENT-O2SWITCH.md). Les séances du dimanche, pas encore passées, sont
 * comptées d'après les présences déjà cochées.
 */
#[AsCommand(
    name: 'app:staff-hours:send-recap',
    description: 'Envoie le récap hebdomadaire des heures encadrées par entraîneur aux responsables RH.',
)]
class StaffHoursRecapCommand extends Command
{
    public function __construct(
        private readonly StaffHoursRecapService $recap,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('week', null, InputOption::VALUE_REQUIRED, 'Un jour (AAAA-MM-JJ) de la semaine à récapituler ; défaut : la semaine en cours.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Envoyer uniquement à cette adresse (test), au lieu des responsables RH.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Afficher le récap sans envoyer d\'e-mail.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawWeek = $input->getOption('week');
        try {
            $day = is_string($rawWeek) && $rawWeek !== '' ? new \DateTimeImmutable($rawWeek) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $output->writeln('<error>Date invalide pour --week (attendu : AAAA-MM-JJ).</error>');

            return Command::FAILURE;
        }
        $monday = WeeklyScheduleService::snapToMonday($day);
        $weekLabel = $this->recap->weekLabel($monday);
        $recap = $this->recap->forWeek($monday);

        $output->writeln(sprintf('%s : %s au total.', $weekLabel, $recap['total']));
        foreach ($recap['sections'] as $section) {
            $output->writeln(sprintf('  %s : %s (%d séance(s))', $section['label'], $section['total'], $section['totalSessions']));
            foreach ($section['rows'] as $row) {
                $output->writeln(sprintf('    - %s : %s (%d séance(s))', $row['user']->getFullName(), $row['duration'], $row['sessions']));
            }
        }
        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        // Destinataires : l'adresse de test, ou tous les responsables RH actifs.
        $to = $input->getOption('to');
        if (is_string($to) && $to !== '') {
            $recipients = [['email' => $to, 'prenom' => null]];
        } else {
            $recipients = array_map(
                static fn (User $u) => ['email' => (string) $u->getEmail(), 'prenom' => $u->getPrenom()],
                $this->users->findActiveByRole(User::ROLE_RH),
            );
        }
        if ($recipients === []) {
            $output->writeln('<comment>Aucun responsable RH actif avec une adresse e-mail : rien envoyé.</comment>');

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($recipients as $recipient) {
            $email = (new TemplatedEmail())
                ->to($recipient['email'])
                ->subject(sprintf('Heures encadrées — %s', $weekLabel))
                ->htmlTemplate('email/staff_hours_recap.html.twig')
                ->textTemplate('email/staff_hours_recap.txt.twig')
                ->context([
                    'prenom' => $recipient['prenom'],
                    'weekLabel' => $weekLabel,
                    'recap' => $recap,
                ]);

            try {
                $this->mailer->send($email);
                $output->writeln(sprintf('Envoyé à %s.', $recipient['email']));
            } catch (TransportExceptionInterface $e) {
                ++$failed;
                $this->logger->warning('Échec de l\'envoi du récap hebdomadaire des heures encadrées', [
                    'to' => $recipient['email'],
                    'error' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('<error>Échec pour %s : %s</error>', $recipient['email'], $e->getMessage()));
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
