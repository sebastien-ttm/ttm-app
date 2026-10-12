<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entraînements occasionnels, suite :
 *  - un événement du calendrier revient à UN SEUL créneau occasionnel lié (event.training_slot_id) : la table de
 *    liaison event_training_slot (migration précédente) est reconvertie, en gardant le premier créneau de chaque
 *    événement, puis supprimée ;
 *  - un article peut intégrer PLUSIEURS créneaux occasionnels : table article_training_slot.
 */
final class Version20261013030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Événement : retour à un seul créneau occasionnel lié ; article : créneaux occasionnels intégrés (article_training_slot).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD training_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_EVENT_TRAINING_SLOT FOREIGN KEY (training_slot_id) REFERENCES training_slot (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_event_training_slot ON event (training_slot_id)');
        // Un événement lié à plusieurs créneaux (migration précédente) garde le premier.
        $this->addSql('UPDATE event e SET e.training_slot_id = (SELECT MIN(l.training_slot_id) FROM event_training_slot l WHERE l.event_id = e.id)');
        $this->addSql('DROP TABLE event_training_slot');

        $this->addSql(<<<'SQL'
            CREATE TABLE article_training_slot (
                article_id INT NOT NULL,
                training_slot_id INT NOT NULL,
                INDEX IDX_ARTICLE_TRAINING_SLOT_ARTICLE (article_id),
                INDEX IDX_ARTICLE_TRAINING_SLOT_SLOT (training_slot_id),
                PRIMARY KEY(article_id, training_slot_id),
                CONSTRAINT FK_ARTICLE_TRAINING_SLOT_ARTICLE FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE,
                CONSTRAINT FK_ARTICLE_TRAINING_SLOT_SLOT FOREIGN KEY (training_slot_id) REFERENCES training_slot (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_training_slot');

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
        $this->addSql('INSERT INTO event_training_slot (event_id, training_slot_id) SELECT id, training_slot_id FROM event WHERE training_slot_id IS NOT NULL');
        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_EVENT_TRAINING_SLOT');
        $this->addSql('ALTER TABLE event DROP COLUMN training_slot_id');
    }
}
