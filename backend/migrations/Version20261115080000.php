<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sondages : testeurs (survey_tester). Un administrateur peut désigner des adhérents (lui-même compris) qui voient
 * et essaient un sondage dans l'appli AVANT sa publication ; leurs réponses d'essai ne sont pas enregistrées.
 */
final class Version20261115080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée survey_tester (testeurs d\'un sondage avant sa publication).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE survey_tester (
                survey_id INT NOT NULL,
                user_id INT NOT NULL,
                INDEX idx_survey_tester_survey (survey_id),
                INDEX idx_survey_tester_user (user_id),
                PRIMARY KEY(survey_id, user_id),
                CONSTRAINT fk_survey_tester_survey FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE,
                CONSTRAINT fk_survey_tester_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE survey_tester');
    }
}
