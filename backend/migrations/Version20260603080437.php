<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Revert : on n'accepte plus que des images pour pool_badge (l'admin
 * extrait le QR du PDF en amont). La colonne mime_type devient inutile.
 */
final class Version20260603080437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop pool_badge.mime_type (plus de PDF — image only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_badge DROP mime_type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_badge ADD mime_type VARCHAR(100) DEFAULT NULL');
    }
}
