<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semaine type : case « Groupes d'entraînement » — seuls les créneaux cochés sont proposés aux encadrants pour créer
 * un groupe. Aucun n'est coché par défaut.
 */
final class Version20261115030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute groups_enabled (décoché) à training_slot_template.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_slot_template ADD groups_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_slot_template DROP groups_enabled');
    }
}
