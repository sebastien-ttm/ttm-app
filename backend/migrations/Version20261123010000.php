<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prêt du minibus du club : demandes d'emprunt (`minibus_booking`), états des lieux (`minibus_check`) et leurs photos
 * (`minibus_check_photo`), réglages avec la charte d'utilisation éditable (`minibus_settings`, une ligne créée avec le
 * texte d'origine), et le rôle complémentaire « gestionnaire du minibus » des membres du CoDir (`user.minibus_manager`).
 *
 * Additive (quatre nouvelles tables, une colonne). À exécuter AVANT de déployer le nouveau code (qui lit ces tables).
 * Les demandes gardent le nom de l'emprunteur et de la personne qui a décidé : supprimer un compte ne les supprime pas.
 */
final class Version20261123010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée minibus_booking, minibus_check, minibus_check_photo, minibus_settings (+ charte) et user.minibus_manager.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD minibus_manager TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql('
            CREATE TABLE minibus_booking (
                id INT AUTO_INCREMENT NOT NULL,
                requester_id INT DEFAULT NULL,
                requester_name VARCHAR(200) NOT NULL,
                start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                reason LONGTEXT NOT NULL,
                with_trailer TINYINT(1) DEFAULT 0 NOT NULL,
                status VARCHAR(16) NOT NULL,
                decided_by_id INT DEFAULT NULL,
                decided_by_name VARCHAR(200) DEFAULT NULL,
                decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                decision_note LONGTEXT DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                charter_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                charter_version VARCHAR(16) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_minibus_booking_dates (start_date, end_date),
                INDEX idx_minibus_booking_status (status),
                INDEX IDX_MINIBUS_BOOKING_REQUESTER (requester_id),
                INDEX IDX_MINIBUS_BOOKING_DECIDED_BY (decided_by_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_minibus_booking_requester FOREIGN KEY (requester_id) REFERENCES user (id) ON DELETE SET NULL,
                CONSTRAINT fk_minibus_booking_decided_by FOREIGN KEY (decided_by_id) REFERENCES user (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE minibus_check (
                id INT AUTO_INCREMENT NOT NULL,
                booking_id INT NOT NULL,
                type VARCHAR(16) NOT NULL,
                submitted_by_id INT DEFAULT NULL,
                submitted_by_name VARCHAR(200) NOT NULL,
                submitted_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                comment_text LONGTEXT DEFAULT NULL,
                has_problem TINYINT(1) DEFAULT 0 NOT NULL,
                problem_description LONGTEXT DEFAULT NULL,
                mileage INT DEFAULT NULL,
                refueled TINYINT(1) DEFAULT 0 NOT NULL,
                cleaned TINYINT(1) DEFAULT 0 NOT NULL,
                INDEX IDX_MINIBUS_CHECK_BOOKING (booking_id),
                INDEX IDX_MINIBUS_CHECK_SUBMITTED_BY (submitted_by_id),
                UNIQUE INDEX uniq_minibus_check_booking_type (booking_id, type),
                PRIMARY KEY(id),
                CONSTRAINT fk_minibus_check_booking FOREIGN KEY (booking_id) REFERENCES minibus_booking (id) ON DELETE CASCADE,
                CONSTRAINT fk_minibus_check_submitted_by FOREIGN KEY (submitted_by_id) REFERENCES user (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE minibus_check_photo (
                id INT AUTO_INCREMENT NOT NULL,
                check_id INT NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                position INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_minibus_photo_check (check_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_minibus_photo_check FOREIGN KEY (check_id) REFERENCES minibus_check (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE minibus_settings (
                id INT AUTO_INCREMENT NOT NULL,
                charter_title VARCHAR(200) NOT NULL,
                charter_content LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        // Une ligne de départ avec le texte d'origine (repris de la fiche plastifiée du minibus) : la charte est modifiable
        // tout de suite depuis le back-office. Texte copié ici pour que la migration reste autonome.
        $charter = trim(<<<'HTML'
            <div>Bienvenue dans le minibus TTM ! Il est mis gratuitement à votre disposition par le club : nous vous demandons donc de le rendre dans le même état que vous l'avez trouvé.</div>
            <div><br></div>
            <div><strong>Avant le départ</strong></div>
            <div>Avant de prendre la route, faites un tour complet du minibus et de la remorque, puis remplissez l'état des lieux de départ dans l'application : photos, kilométrage, remarques éventuelles.</div>
            <div><br></div>
            <div><strong>Pendant l'emprunt</strong></div>
            <div>Indiquez dans l'état des lieux tout problème rencontré (dégât, panne, incident), même mineur.</div>
            <div><br></div>
            <div><strong>Au retour</strong></div>
            <ul>
            <li>Refaites le plein du minibus.</li>
            <li>Nettoyez le minibus et videz-le de tout détritus.</li>
            <li>Remplissez l'état des lieux de retour dans l'application : photos, comment cela s'est passé et problèmes éventuels.</li>
            </ul>
            <div><br></div>
            <div>En acceptant cette charte, vous vous engagez à en respecter les règles.</div>
            HTML);
        $this->addSql(
            'INSERT INTO minibus_settings (charter_title, charter_content, updated_at) VALUES (?, ?, NOW())',
            ['Charte d\'utilisation du minibus TTM', $charter],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE minibus_check_photo');
        $this->addSql('DROP TABLE minibus_check');
        $this->addSql('DROP TABLE minibus_booking');
        $this->addSql('DROP TABLE minibus_settings');
        $this->addSql('ALTER TABLE user DROP minibus_manager');
    }
}
