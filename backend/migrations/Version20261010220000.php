<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Mot de passe oublié » : jetons de réinitialisation envoyés par e-mail
 * (stockés hachés, à usage unique, valables une heure).
 */
final class Version20261010220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée password_reset_token (réinitialisation du mot de passe par e-mail).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE password_reset_token (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_PASSWORD_RESET_TOKEN_HASH (token_hash),
                INDEX idx_password_reset_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_PASSWORD_RESET_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset_token');
    }
}
