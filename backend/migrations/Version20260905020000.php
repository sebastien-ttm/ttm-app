<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute event.carpooling_enabled + crée la table
 * event_carpool_offer pour les propositions de covoiturage des
 * adhérents (conducteur avec places + vélos, ou passager).
 */
final class Version20260905020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add carpooling_enabled on event + event_carpool_offer table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD carpooling_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('
            CREATE TABLE event_carpool_offer (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                event_id INT NOT NULL,
                role VARCHAR(16) NOT NULL,
                seats_available INT DEFAULT NULL,
                bike_slots INT DEFAULT NULL,
                is_full TINYINT(1) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_carpool_user_event (user_id, event_id),
                INDEX idx_carpool_event (event_id),
                CONSTRAINT fk_carpool_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_carpool_event FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE event_carpool_offer');
        $this->addSql('ALTER TABLE event DROP carpooling_enabled');
    }
}
