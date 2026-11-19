<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comité directeur : présents, excusés et invités d'une réunion (`board_meeting_attendee`), repris dans le compte rendu.
 *
 * Additive (nouvelle table). À exécuter AVANT de déployer le nouveau code (qui lit cette table).
 */
final class Version20261120010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée board_meeting_attendee (présents, excusés et invités des réunions du CoDir).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE board_meeting_attendee (
                id INT AUTO_INCREMENT NOT NULL,
                meeting_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                note VARCHAR(120) DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                INDEX idx_board_meeting_attendee_meeting (meeting_id),
                INDEX idx_board_meeting_attendee_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_board_meeting_attendee_meeting FOREIGN KEY (meeting_id) REFERENCES board_meeting (id) ON DELETE CASCADE,
                CONSTRAINT fk_board_meeting_attendee_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_meeting_attendee');
    }
}
