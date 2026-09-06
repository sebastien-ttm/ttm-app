<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute les tables `survey` (sondages configurés depuis le backend)
 * et `survey_response` (une réponse par user, modifiable tant que le
 * sondage est ouvert).
 */
final class Version20260906040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add survey + survey_response tables (sondages avec 4 types de réponse).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE survey (
                id INT AUTO_INCREMENT NOT NULL,
                created_by_id INT DEFAULT NULL,
                title VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                sections JSON DEFAULT NULL,
                audience JSON DEFAULT \'[]\' NOT NULL,
                published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                closes_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX idx_survey_published (published_at),
                INDEX idx_survey_created_by (created_by_id),
                CONSTRAINT fk_survey_created_by FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE survey_response (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                survey_id INT NOT NULL,
                answers JSON NOT NULL,
                submitted_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_survey_response_user (user_id, survey_id),
                INDEX idx_survey_response_survey (survey_id),
                CONSTRAINT fk_survey_response_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_survey_response_survey FOREIGN KEY (survey_id) REFERENCES survey (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE survey_response');
        $this->addSql('DROP TABLE survey');
    }
}
