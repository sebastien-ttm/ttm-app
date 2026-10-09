<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sondages « pas concerné » : un adhérent peut écarter un sondage sans y
 * répondre (coché dans sa liste, exclu du compteur de non répondus).
 */
final class Version20261009010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée survey_dismissal (sondages écartés par un adhérent).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE survey_dismissal (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            survey_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_survey_dismissal_user (user_id, survey_id),
            INDEX idx_survey_dismissal_survey (survey_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_SURVEY_DISMISSAL_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_SURVEY_DISMISSAL_SURVEY FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE survey_dismissal');
    }
}
