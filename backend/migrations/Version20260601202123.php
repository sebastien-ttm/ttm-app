<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suivi des connexions : on stocke la dernière date de login + un compteur
 * cumulé. Les comptes existants partent à NULL / 0 ; les valeurs seront
 * mises à jour à leur prochaine connexion par AuthSuccessListener.
 */
final class Version20260601202123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : last_login_at + login_count pour le suivi des connexions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user`
            ADD last_login_at DATETIME DEFAULT NULL,
            ADD login_count INT NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP last_login_at, DROP login_count');
    }
}
