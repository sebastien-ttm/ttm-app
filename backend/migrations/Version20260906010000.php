<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messagerie v2 :
 *  - user_message : ajout de `scope` (club|trainer|all_trainers) et
 *    `sender_archived_at` (archivage côté expéditeur).
 *  - Nouvelle table `user_message_recipient_state` : archivage
 *    individuel côté destinataire (nécessaire pour scope=all_trainers
 *    et scope=club multi-admins).
 *
 * Backfill : les messages existants avec recipient IS NOT NULL passent
 * en scope='trainer', les autres restent en 'club' (défaut).
 */
final class Version20260906010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messages v2 : scope (club|trainer|all_trainers), archivage sender + par-destinataire.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_message ADD scope VARCHAR(20) DEFAULT 'club' NOT NULL");
        $this->addSql("ALTER TABLE user_message ADD sender_archived_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        // Backfill : les messages ciblant un entraîneur nommé passent en scope=trainer
        $this->addSql("UPDATE user_message SET scope = 'trainer' WHERE recipient_id IS NOT NULL");

        $this->addSql('
            CREATE TABLE user_message_recipient_state (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                message_id INT NOT NULL,
                archived_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_umrs_user_message (user_id, message_id),
                INDEX idx_umrs_user (user_id),
                INDEX idx_umrs_message (message_id),
                CONSTRAINT fk_umrs_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_umrs_message FOREIGN KEY (message_id) REFERENCES user_message (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_message_recipient_state');
        $this->addSql('ALTER TABLE user_message DROP sender_archived_at');
        $this->addSql('ALTER TABLE user_message DROP scope');
    }
}
