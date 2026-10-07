<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Événement lié à un entraînement : créneau de la semaine type ou
 * créneau occasionnel (affiché et cliquable sur la page de l'événement).
 */
final class Version20261007010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute event.training_slot_template_id et event.training_slot_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD training_slot_template_id INT DEFAULT NULL, ADD training_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_EVENT_TRAINING_SLOT_TEMPLATE FOREIGN KEY (training_slot_template_id) REFERENCES training_slot_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_EVENT_TRAINING_SLOT FOREIGN KEY (training_slot_id) REFERENCES training_slot (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_event_training_slot_template ON event (training_slot_template_id)');
        $this->addSql('CREATE INDEX idx_event_training_slot ON event (training_slot_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_EVENT_TRAINING_SLOT_TEMPLATE');
        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_EVENT_TRAINING_SLOT');
        $this->addSql('DROP INDEX idx_event_training_slot_template ON event');
        $this->addSql('DROP INDEX idx_event_training_slot ON event');
        $this->addSql('ALTER TABLE event DROP training_slot_template_id, DROP training_slot_id');
    }
}
