<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comité directeur : réunions et points à l'ordre du jour (ajoutés par les membres du CoDir dans l'appli,
 * réponses saisies en séance dans le back-office).
 */
final class Version20261108020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée board_meeting et board_agenda_item (ordre du jour du comité directeur).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE board_meeting (
                id INT AUTO_INCREMENT NOT NULL,
                scheduled_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                location VARCHAR(150) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_board_meeting_scheduled_at (scheduled_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE board_agenda_item (
                id INT AUTO_INCREMENT NOT NULL,
                meeting_id INT NOT NULL,
                author_id INT DEFAULT NULL,
                pole VARCHAR(32) NOT NULL,
                type VARCHAR(16) NOT NULL,
                text LONGTEXT NOT NULL,
                speaker VARCHAR(120) DEFAULT NULL,
                respondent VARCHAR(120) DEFAULT NULL,
                answer LONGTEXT DEFAULT NULL,
                action_owner VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                answered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_board_agenda_item_meeting (meeting_id),
                INDEX idx_board_agenda_item_author (author_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_board_agenda_item_meeting FOREIGN KEY (meeting_id) REFERENCES board_meeting (id) ON DELETE CASCADE,
                CONSTRAINT fk_board_agenda_item_author FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_agenda_item');
        $this->addSql('DROP TABLE board_meeting');
    }
}
