<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table de présences du staff (encadrants / entraîneurs) :
 *  - sur des créneaux d'entraînement existants (slot_id non null)
 *  - ou sur des tâches hors entraînement (slot_id null, champs saisis)
 *
 * Statut 'scheduled' (positionné à l'avance) ou 'attended' (confirmé).
 */
final class Version20260525195947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table staff_presence : présences encadrants/entraîneurs par semaine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE staff_presence (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            slot_id INT DEFAULT NULL,
            title VARCHAR(120) NOT NULL,
            date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
            start_time TIME NOT NULL COMMENT '(DC2Type:time_immutable)',
            duration_minutes SMALLINT NOT NULL,
            week_starts_at DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
            status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_staff_presence_user_week (user_id, week_starts_at),
            INDEX idx_staff_presence_slot (slot_id),
            UNIQUE INDEX uniq_staff_presence_user_slot (user_id, slot_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE staff_presence ADD CONSTRAINT FK_staff_presence_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE staff_presence ADD CONSTRAINT FK_staff_presence_slot FOREIGN KEY (slot_id) REFERENCES training_slot (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE staff_presence DROP FOREIGN KEY FK_staff_presence_user');
        $this->addSql('ALTER TABLE staff_presence DROP FOREIGN KEY FK_staff_presence_slot');
        $this->addSql('DROP TABLE staff_presence');
    }
}
