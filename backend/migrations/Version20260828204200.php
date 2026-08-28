<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ClubCharter : ajout preview_user_id (aperçu privé).
 *
 * Une charte avec preview_user_id NON NULL est visible uniquement par
 * l'utilisateur ciblé. Permet à l'admin de tester (contenu, formulaire,
 * wording) sans bloquer les autres adhérents. Le champ est ignoré
 * quand is_active = 1 (activation générale prend le dessus).
 */
final class Version20260828204200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ClubCharter : ajout preview_user_id pour tester une charte sur un seul compte avant activation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_charter ADD preview_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE club_charter ADD CONSTRAINT fk_club_charter_preview_user FOREIGN KEY (preview_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_club_charter_preview_user ON club_charter (preview_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_charter DROP FOREIGN KEY fk_club_charter_preview_user');
        $this->addSql('DROP INDEX idx_club_charter_preview_user ON club_charter');
        $this->addSql('ALTER TABLE club_charter DROP preview_user_id');
    }
}
