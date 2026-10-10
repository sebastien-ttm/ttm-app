<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une prise de temps couvre une PÉRIODE (début test_date → fin end_date) au
 * lieu d'une liste de dates : les « autres dates » déjà saisies deviennent
 * le début / la fin de la période, puis la colonne extra_dates disparaît.
 */
final class Version20261010040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'perf_test_session : période (end_date) à la place de extra_dates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE perf_test_session ADD end_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    /** Reprise des données : période = de la plus ancienne à la plus récente des dates déjà saisies. */
    public function postUp(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, test_date, extra_dates FROM perf_test_session WHERE extra_dates IS NOT NULL'
        );
        foreach ($rows as $row) {
            $extra = json_decode((string) $row['extra_dates'], true);
            if (!is_array($extra) || $extra === []) {
                continue;
            }
            $all = array_values(array_filter(
                array_merge([(string) $row['test_date']], array_map('strval', $extra)),
                static fn (string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d),
            ));
            sort($all);
            $start = $all[0];
            $end = $all[count($all) - 1];
            $this->connection->executeStatement(
                'UPDATE perf_test_session SET test_date = :start, end_date = :end WHERE id = :id',
                ['start' => $start, 'end' => $end > $start ? $end : null, 'id' => $row['id']],
            );
        }
        $this->connection->executeStatement('ALTER TABLE perf_test_session DROP extra_dates');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE perf_test_session ADD extra_dates JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE perf_test_session DROP end_date');
    }
}
