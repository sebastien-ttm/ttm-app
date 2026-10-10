<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Temps déclarés par les adhérents (prise de temps individuelle), à valider
 * par les entraîneurs ou l'admin.
 */
final class Version20261010030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée perf_test_declaration (temps déclarés par les adhérents, à accepter ou refuser).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE perf_test_declaration (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            decided_by_id INT DEFAULT NULL,
            test VARCHAR(32) NOT NULL,
            pool_length SMALLINT DEFAULT NULL,
            performed_on DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            time_seconds INT NOT NULL,
            member_comment VARCHAR(500) DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            decision_note VARCHAR(255) DEFAULT NULL,
            INDEX idx_perf_declaration_status (status, created_at),
            INDEX idx_perf_declaration_user (user_id),
            INDEX idx_perf_declaration_decided_by (decided_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_PERF_DECL_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_PERF_DECL_DECIDED_BY FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE perf_test_declaration');
    }
}
