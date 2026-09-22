<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduit la notion de groupes d'adhérents rattachés à une saison :
 *  - member_group : le groupe lui-même (season + name unique, source).
 *  - member_group_member : liaison (group, user) avec métadonnées.
 *
 * Alimentation possible depuis 3 sources (voir MemberGroup::SOURCE_*) :
 * saisie admin, vote de présence d'un événement, réponse de sondage.
 */
final class Version20260922010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Groupes d\'adhérents (member_group + member_group_member).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE member_group (
            id INT AUTO_INCREMENT NOT NULL,
            season_id INT NOT NULL,
            source_event_id INT DEFAULT NULL,
            name VARCHAR(120) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            source VARCHAR(16) DEFAULT \'manual\' NOT NULL,
            source_survey_question VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_member_group_season (season_id),
            INDEX idx_member_group_source_event (source_event_id),
            UNIQUE INDEX uniq_member_group_season_name (season_id, name),
            PRIMARY KEY(id),
            CONSTRAINT FK_MG_SEASON FOREIGN KEY (season_id) REFERENCES training_season (id) ON DELETE CASCADE,
            CONSTRAINT FK_MG_EVENT FOREIGN KEY (source_event_id) REFERENCES event (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE member_group_member (
            id INT AUTO_INCREMENT NOT NULL,
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            joined_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            source VARCHAR(20) DEFAULT \'manual\' NOT NULL,
            INDEX idx_mgm_user (user_id),
            UNIQUE INDEX uniq_group_user (group_id, user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_MGM_GROUP FOREIGN KEY (group_id) REFERENCES member_group (id) ON DELETE CASCADE,
            CONSTRAINT FK_MGM_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE member_group_member');
        $this->addSql('DROP TABLE member_group');
    }
}
