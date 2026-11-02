<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compétitions proposées : une compétition peut comporter plusieurs courses (épreuves), ajoutées par les adhérents.
 */
final class Version20261102060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée race_proposal_course (courses d\'une compétition proposée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE race_proposal_course (
                id INT AUTO_INCREMENT NOT NULL,
                proposal_id INT NOT NULL,
                author_id INT DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                course_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                details VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX idx_race_proposal_course_proposal (proposal_id),
                INDEX idx_race_proposal_course_author (author_id),
                CONSTRAINT fk_race_proposal_course_proposal FOREIGN KEY (proposal_id) REFERENCES race_proposal (id) ON DELETE CASCADE,
                CONSTRAINT fk_race_proposal_course_author FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE race_proposal_course');
    }
}
