<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Groupes d'entraînement adossés à un créneau : séance (créneau de la semaine type + semaine, ou créneau occasionnel),
 * sport (vélo / course à pied), nombre maximal d'adhérents, allure du club (1 à 5) et vitesse du groupe. Les groupes
 * existants gardent ces colonnes à NULL : ils restent gérés à la main par leurs encadrants.
 */
final class Version20261024010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'training_group : créneau, sport, capacité, allure (1 à 5) et vitesse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_group
            ADD slot_template_id INT DEFAULT NULL,
            ADD slot_id INT DEFAULT NULL,
            ADD week_starts_at DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            ADD sport VARCHAR(16) DEFAULT NULL,
            ADD capacity SMALLINT DEFAULT NULL,
            ADD pace_level SMALLINT DEFAULT NULL,
            ADD speed VARCHAR(60) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_training_group_slot_template ON training_group (slot_template_id)');
        $this->addSql('CREATE INDEX idx_training_group_slot ON training_group (slot_id)');
        $this->addSql('ALTER TABLE training_group ADD CONSTRAINT fk_training_group_slot_template FOREIGN KEY (slot_template_id) REFERENCES training_slot_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE training_group ADD CONSTRAINT fk_training_group_slot FOREIGN KEY (slot_id) REFERENCES training_slot (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_group DROP FOREIGN KEY fk_training_group_slot_template');
        $this->addSql('ALTER TABLE training_group DROP FOREIGN KEY fk_training_group_slot');
        $this->addSql('DROP INDEX idx_training_group_slot_template ON training_group');
        $this->addSql('DROP INDEX idx_training_group_slot ON training_group');
        $this->addSql('ALTER TABLE training_group
            DROP slot_template_id,
            DROP slot_id,
            DROP week_starts_at,
            DROP sport,
            DROP capacity,
            DROP pace_level,
            DROP speed');
    }
}
