<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrait de la colonne club_charter.is_active — le formulaire courant
 * est désormais sélectionné par publishedAt DESC (le plus récent).
 * L'aperçu privé (preview_user_id) reste inchangé.
 */
final class Version20260830120003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ClubCharter : drop colonne is_active devenue inutile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_charter DROP COLUMN is_active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_charter ADD is_active TINYINT(1) NOT NULL DEFAULT 0');
    }
}
