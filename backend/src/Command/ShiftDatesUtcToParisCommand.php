<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migration ONE-SHOT : convertit toutes les colonnes DATETIME/TIMESTAMP
 * stockées comme heures UTC naïves vers de l'heure Paris naïve, avec
 * gestion correcte du DST (via PHP setTimezone).
 *
 * À exécuter APRÈS avoir déployé date_default_timezone_set('Europe/Paris')
 * dans public/index.php + bin/console, si le serveur tournait sous UTC.
 *
 * Le token « migrated » est écrit dans une table éphémère
 * `_datetime_shift_flag` pour bloquer les exécutions multiples
 * (double décalage = perte de données).
 */
#[AsCommand(
    name: 'app:datetime:shift-utc-to-paris',
    description: 'Décale toutes les DATETIME (UTC naïves) vers Paris naïves, DST-aware.',
)]
class ShiftDatesUtcToParisCommand extends Command
{
    private const FLAG_TABLE = '_datetime_shift_flag';

    public function __construct(private readonly Connection $conn)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait modifié sans écrire.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Passe outre la confirmation interactive.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        // Verrou d'idempotence : refuse si déjà exécuté (sauf en dry-run)
        $this->ensureFlagTable();
        if (!$dryRun && $this->flagIsSet()) {
            $io->error('Cette migration a déjà été exécutée. La rejouer décalerait les dates une seconde fois.');
            $io->comment('Pour la rejouer volontairement (rare), supprimer la ligne dans '.self::FLAG_TABLE.'.');
            return Command::FAILURE;
        }

        $cols = $this->conn->fetchAllAssociative(<<<SQL
            SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND DATA_TYPE IN ('datetime', 'timestamp')
              AND TABLE_NAME NOT LIKE 'doctrine_%'
              AND TABLE_NAME NOT LIKE '_datetime_shift%'
            ORDER BY TABLE_NAME, COLUMN_NAME
        SQL);

        if ($cols === []) {
            $io->warning('Aucune colonne DATETIME/TIMESTAMP trouvée.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('%s : décalage UTC → Europe/Paris (DST-aware)', $dryRun ? 'DRY-RUN' : 'MIGRATION'));
        $io->text(sprintf('%d colonne(s) à traiter dans %d table(s).', count($cols), count(array_unique(array_column($cols, 'TABLE_NAME')))));

        if (!$dryRun && !$force) {
            if (!$io->confirm('Confirmer la migration ? (Une sauvegarde SQL préalable est fortement recommandée)', false)) {
                $io->warning('Annulé.');
                return Command::SUCCESS;
            }
        }

        $utc = new \DateTimeZone('UTC');
        $paris = new \DateTimeZone('Europe/Paris');
        $totalRows = 0;
        $totalUpdates = 0;

        foreach ($cols as $col) {
            $t = $col['TABLE_NAME'];
            $c = $col['COLUMN_NAME'];

            if (!$this->tableHasIdColumn($t)) {
                $io->note(sprintf('Skip %s.%s : pas de PK `id`.', $t, $c));
                continue;
            }

            $rows = $this->conn->fetchAllAssociative(sprintf(
                'SELECT id, `%s` AS val FROM `%s` WHERE `%s` IS NOT NULL',
                $c, $t, $c,
            ));
            $updatesForCol = 0;
            foreach ($rows as $r) {
                $totalRows++;
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $r['val'], $utc);
                if ($dt === false) {
                    continue;
                }
                $shifted = $dt->setTimezone($paris)->format('Y-m-d H:i:s');
                if ($shifted === $r['val']) {
                    continue; // pas de décalage (rare : très ancienne date pré-DST ?)
                }
                if (!$dryRun) {
                    $this->conn->executeStatement(
                        sprintf('UPDATE `%s` SET `%s` = ? WHERE id = ?', $t, $c),
                        [$shifted, $r['id']],
                    );
                }
                $updatesForCol++;
                $totalUpdates++;
            }
            if ($updatesForCol > 0) {
                $io->writeln(sprintf('  · <info>%s.%s</info> : %d ligne(s) %s', $t, $c, $updatesForCol, $dryRun ? 'à décaler' : 'décalées'));
            }
        }

        $io->success(sprintf(
            '%s — %d ligne(s) inspectée(s), %d %s.',
            $dryRun ? 'DRY-RUN terminé' : 'Migration terminée',
            $totalRows,
            $totalUpdates,
            $dryRun ? 'seraient décalées' : 'décalées',
        ));

        if (!$dryRun) {
            $this->setFlag();
            $io->comment('Verrou d\'idempotence posé dans '.self::FLAG_TABLE.'.');
        }

        return Command::SUCCESS;
    }

    private function ensureFlagTable(): void
    {
        $this->conn->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (executed_at DATETIME NOT NULL PRIMARY KEY)',
            self::FLAG_TABLE,
        ));
    }

    private function flagIsSet(): bool
    {
        return (int) $this->conn->fetchOne(sprintf('SELECT COUNT(*) FROM `%s`', self::FLAG_TABLE)) > 0;
    }

    private function setFlag(): void
    {
        $this->conn->executeStatement(sprintf(
            'INSERT INTO `%s` (executed_at) VALUES (NOW())',
            self::FLAG_TABLE,
        ));
    }

    private function tableHasIdColumn(string $table): bool
    {
        $found = $this->conn->fetchOne(<<<SQL
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = 'id'
            SQL, ['t' => $table]);
        return $found !== false;
    }
}
