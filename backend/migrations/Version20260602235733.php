<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * pool_badge : ajoute mime_type pour permettre au mobile de distinguer
 * un PDF (rendu via iframe/WebBrowser) d'une image (rendu inline).
 */
final class Version20260602235733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'pool_badge.mime_type pour différencier PDF vs image';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_badge ADD mime_type VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_badge DROP mime_type');
    }
}
