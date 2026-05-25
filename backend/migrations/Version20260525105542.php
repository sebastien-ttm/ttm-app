<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table de pièces jointes attachées aux créneaux d'entraînement
 * (GPX, PDF d'échauffement, etc.).
 */
final class Version20260525105542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : pièces jointes par créneau (training_slot_attachment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE training_slot_attachment (
            id INT AUTO_INCREMENT NOT NULL,
            slot_id INT NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size INT NOT NULL,
            uploaded_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_attachment_slot (slot_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE training_slot_attachment ADD CONSTRAINT FK_training_slot_attachment_slot FOREIGN KEY (slot_id) REFERENCES training_slot (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_slot_attachment DROP FOREIGN KEY FK_training_slot_attachment_slot');
        $this->addSql('DROP TABLE training_slot_attachment');
    }
}
