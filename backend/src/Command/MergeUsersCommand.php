<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fusion de deux fiches user (typiquement deux fiches du même
 * adhérent référencé sous plusieurs types de licence FFTri).
 *
 *   php bin/console app:user:merge <keepId> <mergeId>            # dry-run par défaut
 *   php bin/console app:user:merge <keepId> <mergeId> --commit   # exécute réellement
 *
 * Découvre automatiquement toutes les FKs référençant `user(id)` via
 * INFORMATION_SCHEMA, et migre les valeurs `mergeId` → `keepId` pour
 * chaque colonne. Les conflits sur contrainte unique (ex : deux votes
 * du même user sur le même event après fusion) sont résolus en
 * supprimant la ligne « mergeId » AVANT le UPDATE.
 *
 * Transaction unique : rollback en cas d'erreur, aucune donnée
 * partiellement migrée.
 */
#[AsCommand(name: 'app:user:merge', description: 'Fusionne deux fiches user (transfère toutes les données vers keepId).')]
class MergeUsersCommand extends Command
{
    public function __construct(private readonly Connection $conn)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('keepId', InputArgument::REQUIRED, 'ID du user à CONSERVER')
            ->addArgument('mergeId', InputArgument::REQUIRED, 'ID du user à FUSIONNER (sera supprimé après transfert)')
            ->addOption('commit', null, InputOption::VALUE_NONE, 'Exécute réellement (sans ce flag : dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keepId = (int) $input->getArgument('keepId');
        $mergeId = (int) $input->getArgument('mergeId');
        $commit = (bool) $input->getOption('commit');

        if ($keepId === $mergeId) {
            $io->error('keepId et mergeId doivent être différents.');
            return Command::FAILURE;
        }

        $keep = $this->conn->fetchAssociative('SELECT id, nom, prenom, num_licence, email, is_active FROM `user` WHERE id = ?', [$keepId]);
        $merge = $this->conn->fetchAssociative('SELECT id, nom, prenom, num_licence, email, is_active FROM `user` WHERE id = ?', [$mergeId]);
        if ($keep === false || $merge === false) {
            $io->error('User inconnu : '.($keep === false ? "keepId=$keepId" : "mergeId=$mergeId"));
            return Command::FAILURE;
        }

        $io->section('Fiches concernées');
        $io->table(
            ['Rôle', 'ID', 'Nom', 'Prénom', 'Licence', 'Email', 'Actif'],
            [
                ['CONSERVE',  $keep['id'],  $keep['nom'],  $keep['prenom'],  $keep['num_licence'],  $keep['email'],  $keep['is_active'] ? 'oui' : 'non'],
                ['FUSIONNE',  $merge['id'], $merge['nom'], $merge['prenom'], $merge['num_licence'], $merge['email'], $merge['is_active'] ? 'oui' : 'non'],
            ],
        );

        // Découverte auto des FKs référençant `user(id)`.
        $fks = $this->conn->fetchAllAssociative(
            "SELECT TABLE_NAME, COLUMN_NAME "
            ."FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE "
            ."WHERE REFERENCED_TABLE_SCHEMA = DATABASE() "
            ."  AND REFERENCED_TABLE_NAME = 'user' "
            ."  AND REFERENCED_COLUMN_NAME = 'id' "
            ."ORDER BY TABLE_NAME, COLUMN_NAME"
        );

        $io->section('Colonnes à migrer ('.count($fks).' FKs)');
        foreach ($fks as $fk) {
            $io->writeln(sprintf('  <info>%s.%s</info>', $fk['TABLE_NAME'], $fk['COLUMN_NAME']));
        }

        if (!$commit) {
            $io->warning('DRY-RUN : aucune modification effectuée. Ajoutez --commit pour exécuter.');
            return Command::SUCCESS;
        }

        $io->section('Exécution de la fusion');
        $this->conn->beginTransaction();
        try {
            $totalRows = 0;
            foreach ($fks as $fk) {
                $table = $fk['TABLE_NAME'];
                $col = $fk['COLUMN_NAME'];

                // Trouve les unique constraints qui incluent cette colonne
                // (ex : uniq_attendance_user_event(user_id, event_id)).
                // Si présentes, on retire d'abord les lignes de merge qui
                // conflicteraient avec une ligne de keep déjà existante.
                $duplicates = $this->deleteConflictingDuplicates($table, $col, $keepId, $mergeId);
                if ($duplicates > 0) {
                    $io->writeln(sprintf('  <comment>%s</comment> : %d ligne(s) doublon supprimée(s) avant migration', $table, $duplicates));
                }

                $stmt = $this->conn->prepare("UPDATE `$table` SET `$col` = :keep WHERE `$col` = :merge");
                $stmt->bindValue('keep', $keepId);
                $stmt->bindValue('merge', $mergeId);
                $affected = (int) $stmt->executeStatement();
                if ($affected > 0) {
                    $io->writeln(sprintf('  <info>%s.%s</info> : %d ligne(s) réattribuée(s)', $table, $col, $affected));
                    $totalRows += $affected;
                }
            }

            $delStmt = $this->conn->prepare('DELETE FROM `user` WHERE id = :id');
            $delStmt->bindValue('id', $mergeId);
            $delStmt->executeStatement();

            $this->conn->commit();
            $io->success(sprintf('Fusion effectuée. %d lignes transférées, user #%d supprimé.', $totalRows, $mergeId));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $io->error('Erreur : '.$e->getMessage().' — transaction annulée, aucune modification appliquée.');
            return Command::FAILURE;
        }
    }

    /**
     * Supprime les lignes de `merge` qui produiraient une collision
     * unique après fusion. Ex : si (user_id, event_id) est unique et
     * qu'à la fois keep et merge ont voté sur l'event 42, la ligne
     * de merge est effacée avant que le UPDATE ne pousse merge.user_id
     * = keep.user_id, ce qui produirait un doublon.
     */
    private function deleteConflictingDuplicates(string $table, string $userCol, int $keepId, int $mergeId): int
    {
        $indexes = $this->conn->fetchAllAssociative(
            "SELECT INDEX_NAME, COLUMN_NAME "
            ."FROM INFORMATION_SCHEMA.STATISTICS "
            ."WHERE TABLE_SCHEMA = DATABASE() "
            ."  AND TABLE_NAME = ? "
            ."  AND NON_UNIQUE = 0 "
            ."ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$table]
        );
        if ($indexes === []) return 0;

        // Regroupe par nom d'index.
        $indexCols = [];
        foreach ($indexes as $row) {
            $indexCols[$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
        }

        $total = 0;
        foreach ($indexCols as $idxName => $cols) {
            if ($idxName === 'PRIMARY') continue;
            if (!in_array($userCol, $cols, true)) continue;
            $otherCols = array_values(array_filter($cols, fn ($c) => $c !== $userCol));
            if ($otherCols === []) continue; // unique(user_id) seul — géré par UPDATE natif

            // Supprime les lignes de merge qui ont un pendant chez keep sur
            // les autres colonnes du même index.
            $joinConds = array_map(fn ($c) => "m.`$c` = k.`$c`", $otherCols);
            $sql = "DELETE m FROM `$table` m "
                 ."INNER JOIN `$table` k ON k.`$userCol` = :keep AND ".implode(' AND ', $joinConds)." "
                 ."WHERE m.`$userCol` = :merge";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue('keep', $keepId);
            $stmt->bindValue('merge', $mergeId);
            $total += (int) $stmt->executeStatement();
        }
        return $total;
    }
}
