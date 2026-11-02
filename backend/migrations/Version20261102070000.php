<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compétitions proposées : les adhérents indiquent la ou les courses qu'ils font (y participer vaut présence à la
 * compétition).
 */
final class Version20261102070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée race_proposal_course_participant (adhérents inscrits à une course d\'une compétition).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE race_proposal_course_participant (
                course_id INT NOT NULL,
                user_id INT NOT NULL,
                PRIMARY KEY(course_id, user_id),
                INDEX idx_race_course_participant_user (user_id),
                CONSTRAINT fk_race_course_participant_course FOREIGN KEY (course_id) REFERENCES race_proposal_course (id) ON DELETE CASCADE,
                CONSTRAINT fk_race_course_participant_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE race_proposal_course_participant');
    }
}
