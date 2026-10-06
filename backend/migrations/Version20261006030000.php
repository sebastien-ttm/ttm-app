<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tests chronométrés (1500 m CAP, 400 m natation 25/50 m, montée 2 km
 * vélo) : séances et temps saisis par les entraîneurs.
 */
final class Version20261006030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée perf_test_session et perf_test_result (tests chronométrés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE perf_test_session (
            id INT AUTO_INCREMENT NOT NULL,
            created_by_id INT DEFAULT NULL,
            test VARCHAR(32) NOT NULL,
            test_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            pool_length SMALLINT DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_perf_session_test_date (test, test_date),
            INDEX idx_perf_session_created_by (created_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_PERF_SESSION_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE perf_test_result (
            id INT AUTO_INCREMENT NOT NULL,
            session_id INT NOT NULL,
            user_id INT NOT NULL,
            entered_by_id INT DEFAULT NULL,
            time_seconds INT NOT NULL,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_perf_result_session_user (session_id, user_id),
            INDEX idx_perf_result_user (user_id),
            INDEX idx_perf_result_entered_by (entered_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_PERF_RESULT_SESSION FOREIGN KEY (session_id) REFERENCES perf_test_session (id) ON DELETE CASCADE,
            CONSTRAINT FK_PERF_RESULT_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_PERF_RESULT_ENTERED_BY FOREIGN KEY (entered_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE perf_test_result');
        $this->addSql('DROP TABLE perf_test_session');
    }
}
