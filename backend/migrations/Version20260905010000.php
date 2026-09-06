<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute event.vote_enabled + crée la table event_attendance pour le
 * vote de présence des adhérents (« j'y serai / je n'y serai pas /
 * je ne sais pas encore »).
 */
final class Version20260905010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vote_enabled on event + event_attendance table for presence voting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD vote_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('
            CREATE TABLE event_attendance (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                event_id INT NOT NULL,
                status VARCHAR(16) NOT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_attendance_user_event (user_id, event_id),
                INDEX idx_attendance_event (event_id),
                CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_att_event FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE event_attendance');
        $this->addSql('ALTER TABLE event DROP vote_enabled');
    }
}
