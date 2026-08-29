<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UserSeasonMembership : lien user × saison pour tracer les adhésions
 * historiques par saison (statistiques + gestion des anciens adhérents).
 */
final class Version20260829213905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UserSeasonMembership : lien adhérent × saison + snapshots licence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE user_season_membership (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                season_id INT NOT NULL,
                imported_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                statut_licence VARCHAR(100) DEFAULT NULL,
                type_licence VARCHAR(100) DEFAULT NULL,
                categorie_age VARCHAR(100) DEFAULT NULL,
                UNIQUE INDEX uniq_user_season (user_id, season_id),
                INDEX idx_usm_season (season_id),
                INDEX IDX_usm_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE user_season_membership ADD CONSTRAINT fk_usm_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_season_membership ADD CONSTRAINT fk_usm_season FOREIGN KEY (season_id) REFERENCES training_season (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_season_membership');
    }
}
