<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Généralise event_carpool_offer pour accepter comme sujet SOIT un
 * événement SOIT une proposition de course (comme
 * marketplace_conversation pour listing/bibOffer) : event_id devient
 * nullable, ajout de race_proposal_id. Ajoute aussi
 * race_proposal.carpooling_enabled (case à cocher par l'auteur),
 * miroir de event.carpooling_enabled.
 */
final class Version20260930030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Covoiturage : généralise event_carpool_offer aux propositions de course.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_carpool_offer MODIFY event_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event_carpool_offer ADD race_proposal_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event_carpool_offer ADD CONSTRAINT fk_carpool_race FOREIGN KEY (race_proposal_id) REFERENCES race_proposal (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX uniq_carpool_user_race ON event_carpool_offer (user_id, race_proposal_id)');
        $this->addSql('CREATE INDEX idx_carpool_race ON event_carpool_offer (race_proposal_id)');

        $this->addSql('ALTER TABLE race_proposal ADD carpooling_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race_proposal DROP carpooling_enabled');

        $this->addSql('DROP INDEX idx_carpool_race ON event_carpool_offer');
        $this->addSql('DROP INDEX uniq_carpool_user_race ON event_carpool_offer');
        $this->addSql('ALTER TABLE event_carpool_offer DROP FOREIGN KEY fk_carpool_race');
        $this->addSql('ALTER TABLE event_carpool_offer DROP race_proposal_id');
        $this->addSql('ALTER TABLE event_carpool_offer MODIFY event_id INT NOT NULL');
    }
}
