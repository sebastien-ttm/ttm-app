<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LoginEvent : historique fin des connexions (mobile + admin) pour
 * agréger les statistiques d'usage. Complète User.lastLoginAt /
 * User.loginCount qui restent inchangés.
 */
final class Version20260605060446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LoginEvent : historique des connexions pour stats admin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE login_event (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                occurred_at DATETIME NOT NULL,
                channel VARCHAR(16) NOT NULL,
                INDEX idx_login_event_occurred_at (occurred_at),
                INDEX idx_login_event_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE login_event ADD CONSTRAINT fk_login_event_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE login_event');
    }
}
