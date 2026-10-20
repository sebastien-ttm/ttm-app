<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Groupes d'entraînement « à la volée » créés par un encadrant / entraîneur depuis l'espace Staff :
 *  - training_group : le groupe (éphémère : valable jusqu'au prochain créneau), ses encadrants et ses adhérents ;
 *  - training_group_comment : commentaires des encadrants, avec éventuellement un point GPS ;
 *  - training_group_attachment : traces GPX téléchargeables ;
 *  - training_group_position : dernière position partagée en direct par chaque encadrant ;
 *  - user.telephone_urgence : téléphone du contact d'urgence (colonne « Téléphone du contact d'urgence » du CSV FFTri).
 */
final class Version20261020010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Groupes d\'entraînement à la volée (groupe, encadrants, membres, commentaires, GPX, positions) + user.telephone_urgence.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD telephone_urgence VARCHAR(32) DEFAULT NULL');

        $this->addSql('
            CREATE TABLE training_group (
                id INT AUTO_INCREMENT NOT NULL,
                created_by_id INT NOT NULL,
                name VARCHAR(80) DEFAULT NULL,
                info LONGTEXT DEFAULT NULL,
                session_title VARCHAR(160) DEFAULT NULL,
                session_starts_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX idx_training_group_created_by (created_by_id),
                INDEX idx_training_group_expires (expires_at),
                CONSTRAINT fk_training_group_created_by FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        foreach (['coach', 'member'] as $role) {
            $this->addSql(sprintf('
                CREATE TABLE training_group_%1$s (
                    training_group_id INT NOT NULL,
                    user_id INT NOT NULL,
                    PRIMARY KEY(training_group_id, user_id),
                    INDEX idx_tg_%1$s_group (training_group_id),
                    INDEX idx_tg_%1$s_user (user_id),
                    CONSTRAINT fk_tg_%1$s_group FOREIGN KEY (training_group_id) REFERENCES training_group (id) ON DELETE CASCADE,
                    CONSTRAINT fk_tg_%1$s_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            ', $role));
        }

        $this->addSql('
            CREATE TABLE training_group_comment (
                id INT AUTO_INCREMENT NOT NULL,
                training_group_id INT NOT NULL,
                author_id INT NOT NULL,
                content LONGTEXT DEFAULT NULL,
                latitude NUMERIC(9, 6) DEFAULT NULL,
                longitude NUMERIC(9, 6) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX idx_tgc_group (training_group_id),
                CONSTRAINT fk_tgc_group FOREIGN KEY (training_group_id) REFERENCES training_group (id) ON DELETE CASCADE,
                CONSTRAINT fk_tgc_author FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE training_group_attachment (
                id INT AUTO_INCREMENT NOT NULL,
                training_group_id INT NOT NULL,
                uploaded_by_id INT NOT NULL,
                stored_name VARCHAR(64) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                size INT NOT NULL,
                uploaded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX idx_tga_group (training_group_id),
                CONSTRAINT fk_tga_group FOREIGN KEY (training_group_id) REFERENCES training_group (id) ON DELETE CASCADE,
                CONSTRAINT fk_tga_user FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE training_group_position (
                id INT AUTO_INCREMENT NOT NULL,
                training_group_id INT NOT NULL,
                user_id INT NOT NULL,
                latitude NUMERIC(9, 6) NOT NULL,
                longitude NUMERIC(9, 6) NOT NULL,
                accuracy INT DEFAULT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_tgp_group_user (training_group_id, user_id),
                INDEX idx_tgp_user (user_id),
                CONSTRAINT fk_tgp_group FOREIGN KEY (training_group_id) REFERENCES training_group (id) ON DELETE CASCADE,
                CONSTRAINT fk_tgp_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_group_position');
        $this->addSql('DROP TABLE training_group_attachment');
        $this->addSql('DROP TABLE training_group_comment');
        $this->addSql('DROP TABLE training_group_member');
        $this->addSql('DROP TABLE training_group_coach');
        $this->addSql('DROP TABLE training_group');
        $this->addSql('ALTER TABLE `user` DROP telephone_urgence');
    }
}
