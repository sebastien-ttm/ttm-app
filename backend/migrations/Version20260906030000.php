<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute les tables `admin_notice` (messages ponctuels avec acquittement,
 * décorrélés du tunnel charte de début de saison) et
 * `admin_notice_acknowledgement` (trace par user).
 */
final class Version20260906030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_notice + admin_notice_acknowledgement (messages ponctuels avec acquittement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE admin_notice (
                id INT AUTO_INCREMENT NOT NULL,
                created_by_id INT DEFAULT NULL,
                title VARCHAR(200) NOT NULL,
                content LONGTEXT NOT NULL,
                acknowledge_label VARCHAR(60) DEFAULT \'J\\\'ai compris\' NOT NULL,
                audience JSON DEFAULT \'[]\' NOT NULL,
                published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX idx_admin_notice_published (published_at),
                INDEX idx_admin_notice_created_by (created_by_id),
                CONSTRAINT fk_admin_notice_created_by FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE admin_notice_acknowledgement (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                notice_id INT NOT NULL,
                acknowledged_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_notice_ack (user_id, notice_id),
                INDEX idx_notice_ack_user (user_id),
                INDEX idx_notice_ack_notice (notice_id),
                CONSTRAINT fk_notice_ack_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_notice_ack_notice FOREIGN KEY (notice_id) REFERENCES admin_notice (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_notice_acknowledgement');
        $this->addSql('DROP TABLE admin_notice');
    }
}
