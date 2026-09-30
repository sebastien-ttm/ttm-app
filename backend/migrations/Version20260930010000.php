<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Semaine de présence type du staff : marque, par créneau de la semaine
 * type du club, si un encadrant/entraîneur y est présent en temps
 * normal — sert à positionner sa présence en un clic sur une semaine
 * précise (StaffPresenceController::applyTemplate).
 */
final class Version20260930010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée staff_presence_template (semaine de présence type du staff).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE staff_presence_template (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            slot_template_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_staff_presence_template_user_slot (user_id, slot_template_id),
            INDEX idx_staff_presence_template_user (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_SPT_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_SPT_SLOT_TEMPLATE FOREIGN KEY (slot_template_id) REFERENCES training_slot_template (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE staff_presence_template');
    }
}
