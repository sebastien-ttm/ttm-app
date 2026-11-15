<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jeunes d'associations sportives externes : marqués lors de l'import CSV, sans accès à l'application.
 */
final class Version20261115070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute user.external_association (jeune d\'association sportive externe, sans accès à l\'appli).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD external_association TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP external_association');
    }
}
