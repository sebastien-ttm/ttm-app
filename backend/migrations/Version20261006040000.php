<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comptes temporaires pour les adhérents dont la licence n'est pas
 * encore validée par la ligue (complétés par l'import CSV).
 */
final class Version20261006040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute user.pending_licence_since (compte en attente de licence).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD pending_licence_since DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP pending_licence_since');
    }
}
