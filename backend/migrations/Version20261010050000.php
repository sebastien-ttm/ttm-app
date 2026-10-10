<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications push web : abonnements des navigateurs / applis web installées.
 */
final class Version20261010050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée web_push_subscription (abonnements Web Push).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE web_push_subscription (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            endpoint LONGTEXT NOT NULL,
            endpoint_hash VARCHAR(64) NOT NULL,
            p256dh VARCHAR(128) NOT NULL,
            auth_secret VARCHAR(64) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_web_push_endpoint (endpoint_hash),
            INDEX idx_web_push_user (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_WEB_PUSH_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE web_push_subscription');
    }
}
