<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bénévolat d'événement (Breathman 2027) : campagnes, pôles et leurs responsables, fiches (activités et postes
 * terrain), candidatures des adhérents.
 */
final class Version20261108010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée volunteer_campaign, volunteer_pole, volunteer_pole_manager, volunteer_role et volunteer_application.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE volunteer_campaign (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                starts_on DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                ends_on DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                location VARCHAR(150) DEFAULT NULL,
                applications_deadline DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                intro LONGTEXT DEFAULT NULL,
                published TINYINT(1) NOT NULL,
                applications_open TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_volunteer_campaign_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE volunteer_pole (
                id INT AUTO_INCREMENT NOT NULL,
                campaign_id INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                manager_names VARCHAR(120) DEFAULT NULL,
                color VARCHAR(16) DEFAULT \'teal\' NOT NULL,
                position INT DEFAULT 0 NOT NULL,
                INDEX idx_volunteer_pole_campaign (campaign_id),
                UNIQUE INDEX uniq_volunteer_pole_campaign_name (campaign_id, name),
                PRIMARY KEY(id),
                CONSTRAINT fk_volunteer_pole_campaign FOREIGN KEY (campaign_id) REFERENCES volunteer_campaign (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE volunteer_pole_manager (
                pole_id INT NOT NULL,
                user_id INT NOT NULL,
                INDEX idx_volunteer_pole_manager_user (user_id),
                PRIMARY KEY(pole_id, user_id),
                CONSTRAINT fk_volunteer_pole_manager_pole FOREIGN KEY (pole_id) REFERENCES volunteer_pole (id) ON DELETE CASCADE,
                CONSTRAINT fk_volunteer_pole_manager_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE volunteer_role (
                id INT AUTO_INCREMENT NOT NULL,
                pole_id INT NOT NULL,
                activity_id INT DEFAULT NULL,
                kind VARCHAR(16) NOT NULL,
                name VARCHAR(120) NOT NULL,
                title VARCHAR(150) DEFAULT NULL,
                pitch LONGTEXT DEFAULT NULL,
                content JSON NOT NULL,
                published TINYINT(1) NOT NULL,
                required_count INT DEFAULT NULL,
                manual_names VARCHAR(255) DEFAULT NULL,
                position INT DEFAULT 0 NOT NULL,
                INDEX idx_volunteer_role_pole (pole_id),
                INDEX idx_volunteer_role_activity (activity_id),
                UNIQUE INDEX uniq_volunteer_role_pole_kind_name (pole_id, kind, name),
                PRIMARY KEY(id),
                CONSTRAINT fk_volunteer_role_pole FOREIGN KEY (pole_id) REFERENCES volunteer_pole (id) ON DELETE CASCADE,
                CONSTRAINT fk_volunteer_role_activity FOREIGN KEY (activity_id) REFERENCES volunteer_role (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE volunteer_application (
                id INT AUTO_INCREMENT NOT NULL,
                role_id INT NOT NULL,
                user_id INT NOT NULL,
                decided_by_id INT DEFAULT NULL,
                assigned_by_id INT DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                message LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_volunteer_application_user (user_id),
                INDEX idx_volunteer_application_status (status),
                INDEX idx_volunteer_application_decided_by (decided_by_id),
                INDEX idx_volunteer_application_assigned_by (assigned_by_id),
                UNIQUE INDEX uniq_volunteer_application_role_user (role_id, user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_volunteer_application_role FOREIGN KEY (role_id) REFERENCES volunteer_role (id) ON DELETE CASCADE,
                CONSTRAINT fk_volunteer_application_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_volunteer_application_decided_by FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL,
                CONSTRAINT fk_volunteer_application_assigned_by FOREIGN KEY (assigned_by_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE volunteer_application');
        $this->addSql('DROP TABLE volunteer_role');
        $this->addSql('DROP TABLE volunteer_pole_manager');
        $this->addSql('DROP TABLE volunteer_pole');
        $this->addSql('DROP TABLE volunteer_campaign');
    }
}
