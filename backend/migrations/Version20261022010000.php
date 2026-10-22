<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Déplacements : table trip, participants (trip_participant), événements rattachés (trip_event) et lien optionnel
 * article.trip_id pour les articles dédiés à un déplacement (réservés à ses participants).
 */
final class Version20261022010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'trip, trip_participant, trip_event et article.trip_id : déplacements du club.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE trip (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(150) NOT NULL,
                icon VARCHAR(32) DEFAULT NULL,
                location VARCHAR(200) DEFAULT NULL,
                starts_on DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                ends_on DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                description LONGTEXT DEFAULT NULL,
                published TINYINT(1) NOT NULL,
                notified_user_ids JSON NOT NULL DEFAULT \'[]\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('
            CREATE TABLE trip_participant (
                trip_id INT NOT NULL,
                user_id INT NOT NULL,
                INDEX idx_trip_participant_trip (trip_id),
                INDEX idx_trip_participant_user (user_id),
                PRIMARY KEY(trip_id, user_id),
                CONSTRAINT fk_trip_participant_trip FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE CASCADE,
                CONSTRAINT fk_trip_participant_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('
            CREATE TABLE trip_event (
                trip_id INT NOT NULL,
                event_id INT NOT NULL,
                INDEX idx_trip_event_trip (trip_id),
                INDEX idx_trip_event_event (event_id),
                PRIMARY KEY(trip_id, event_id),
                CONSTRAINT fk_trip_event_trip FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE CASCADE,
                CONSTRAINT fk_trip_event_event FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        // RESTRICT : on ne supprime pas un déplacement qui a encore des articles dédiés (ils deviendraient publics).
        $this->addSql('ALTER TABLE article ADD trip_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT fk_article_trip FOREIGN KEY (trip_id) REFERENCES trip (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_article_trip ON article (trip_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY fk_article_trip');
        $this->addSql('DROP INDEX idx_article_trip ON article');
        $this->addSql('ALTER TABLE article DROP trip_id');
        $this->addSql('DROP TABLE trip_event');
        $this->addSql('DROP TABLE trip_participant');
        $this->addSql('DROP TABLE trip');
    }
}
