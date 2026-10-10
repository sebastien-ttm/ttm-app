<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mailings groupés depuis le backend : le mailing, ses destinataires (un
 * instantané de la liste au moment du lancement, avec le statut d'envoi de
 * chacun) et la désinscription des adhérents (user.mailing_opt_out_at).
 */
final class Version20261010210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée mailing et mailing_recipient, ajoute user.mailing_opt_out_at (désinscription des mailings).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE `user` ADD mailing_opt_out_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE mailing (
                id INT AUTO_INCREMENT NOT NULL,
                created_by_id INT DEFAULT NULL,
                subject VARCHAR(200) NOT NULL,
                body_html LONGTEXT NOT NULL,
                audience JSON NOT NULL DEFAULT '[]',
                include_external TINYINT(1) DEFAULT 0 NOT NULL,
                reply_to VARCHAR(180) DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                status_note VARCHAR(255) DEFAULT NULL,
                run_token VARCHAR(32) DEFAULT '' NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                started_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                finished_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_mailing_status (status),
                INDEX idx_mailing_created_by (created_by_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_MAILING_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE mailing_recipient (
                id INT AUTO_INCREMENT NOT NULL,
                mailing_id INT NOT NULL,
                user_id INT DEFAULT NULL,
                email VARCHAR(180) NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL,
                error VARCHAR(500) DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_mailing_recipient_status (mailing_id, status),
                INDEX idx_mailing_recipient_sent (sent_at),
                INDEX idx_mailing_recipient_user (user_id),
                UNIQUE INDEX uniq_mailing_recipient_email (mailing_id, email),
                PRIMARY KEY(id),
                CONSTRAINT FK_MAILING_RECIPIENT_MAILING FOREIGN KEY (mailing_id) REFERENCES mailing (id) ON DELETE CASCADE,
                CONSTRAINT FK_MAILING_RECIPIENT_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mailing_recipient');
        $this->addSql('DROP TABLE mailing');
        $this->addSql('ALTER TABLE `user` DROP mailing_opt_out_at');
    }
}
