<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Changement d'adresse e-mail en libre-service : demandes en attente de
 * confirmation via un lien envoyé à l'adresse actuelle du compte.
 */
final class Version20260924020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée email_change_request (changement d\'e-mail en libre-service).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_change_request (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            new_email VARCHAR(180) NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_ECR_TOKEN_HASH (token_hash),
            INDEX idx_email_change_user (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_ECR_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_change_request');
    }
}
