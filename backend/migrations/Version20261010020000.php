<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Temps des anciens adhérents (import des saisons passées) : un résultat de
 * test peut n'avoir qu'un nom (legacy_name) et pas de compte (user_id NULL).
 */
final class Version20261010020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'perf_test_result : user_id nullable + legacy_name (anciens adhérents sans compte).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE perf_test_result CHANGE user_id user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE perf_test_result ADD legacy_name VARCHAR(160) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_perf_result_session_legacy ON perf_test_result (session_id, legacy_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM perf_test_result WHERE user_id IS NULL');
        $this->addSql('DROP INDEX uniq_perf_result_session_legacy ON perf_test_result');
        $this->addSql('ALTER TABLE perf_test_result DROP legacy_name');
        $this->addSql('ALTER TABLE perf_test_result CHANGE user_id user_id INT NOT NULL');
    }
}
