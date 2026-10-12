<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un événement du calendrier peut être lié à PLUSIEURS créneaux d'entraînement, uniquement occasionnels :
 *  - nouvelle table de liaison event_training_slot, alimentée par l'ancien lien unique (event.training_slot_id) ;
 *  - l'ancien lien unique est supprimé, ainsi que le lien vers un créneau de la semaine type
 *    (event.training_slot_template_id), qui n'est plus sélectionnable.
 */
final class Version20261013020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Événements : liaison à plusieurs créneaux occasionnels (event_training_slot), retrait du lien vers la semaine type.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE event_training_slot (
                event_id INT NOT NULL,
                training_slot_id INT NOT NULL,
                INDEX IDX_EVENT_TRAINING_SLOT_EVENT (event_id),
                INDEX IDX_EVENT_TRAINING_SLOT_SLOT (training_slot_id),
                PRIMARY KEY(event_id, training_slot_id),
                CONSTRAINT FK_EVENT_TRAINING_SLOT_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE,
                CONSTRAINT FK_EVENT_TRAINING_SLOT_SLOT FOREIGN KEY (training_slot_id) REFERENCES training_slot (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // Les événements déjà liés à un créneau occasionnel gardent leur lien.
        $this->addSql('INSERT INTO event_training_slot (event_id, training_slot_id) SELECT id, training_slot_id FROM event WHERE training_slot_id IS NOT NULL');

        // Les clés étrangères sont retrouvées par leur colonne (leur nom peut différer d'un environnement à l'autre) ;
        // supprimer la colonne retire aussi son index.
        foreach (['training_slot_id', 'training_slot_template_id'] as $column) {
            foreach ($this->foreignKeysOn('event', $column) as $name) {
                $this->addSql(sprintf('ALTER TABLE event DROP FOREIGN KEY %s', $name));
            }
            $this->addSql(sprintf('ALTER TABLE event DROP COLUMN %s', $column));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD training_slot_template_id INT DEFAULT NULL, ADD training_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_EVENT_TRAINING_SLOT_TEMPLATE FOREIGN KEY (training_slot_template_id) REFERENCES training_slot_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_EVENT_TRAINING_SLOT FOREIGN KEY (training_slot_id) REFERENCES training_slot (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_event_training_slot_template ON event (training_slot_template_id)');
        $this->addSql('CREATE INDEX idx_event_training_slot ON event (training_slot_id)');
        // Retour au lien unique : on garde le premier créneau lié de chaque événement.
        $this->addSql('UPDATE event e SET e.training_slot_id = (SELECT MIN(l.training_slot_id) FROM event_training_slot l WHERE l.event_id = e.id)');
        $this->addSql('DROP TABLE event_training_slot');
    }

    /** @return list<string> noms des clés étrangères portées par la colonne */
    private function foreignKeysOn(string $table, string $column): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column],
        );
    }
}
