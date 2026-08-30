<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colonne invoice_sequence sur user_season_membership : numéro d'ordre
 * stable de la facture dans la saison. Assigné au premier rendu PDF,
 * réutilisé ensuite pour composer « TTM-{saison}-{seq:02d} ».
 */
final class Version20260830200942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invoice_sequence column on user_season_membership.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership ADD invoice_sequence INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership DROP invoice_sequence');
    }
}
