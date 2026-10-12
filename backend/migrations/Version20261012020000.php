<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entraînements sur inscription : réglages « sur inscription » + nombre de places (semaine type et créneaux
 * occasionnels), et inscriptions des adhérents (une par semaine et par adhérent, garantie par l'index unique).
 */
final class Version20261012020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entraînements sur inscription : registration_required/capacity + table training_slot_registration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_slot_template ADD registration_required TINYINT(1) DEFAULT 0 NOT NULL, ADD capacity SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE training_slot ADD registration_required TINYINT(1) DEFAULT 0 NOT NULL, ADD capacity SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE training_slot_registration (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                template_id INT DEFAULT NULL,
                slot_id INT DEFAULT NULL,
                week_starts_at DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_training_registration_template_week (template_id, week_starts_at),
                INDEX idx_training_registration_slot (slot_id),
                UNIQUE INDEX uniq_training_registration_user_week (user_id, week_starts_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_TRAINING_REGISTRATION_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT FK_TRAINING_REGISTRATION_TEMPLATE FOREIGN KEY (template_id) REFERENCES training_slot_template (id) ON DELETE CASCADE,
                CONSTRAINT FK_TRAINING_REGISTRATION_SLOT FOREIGN KEY (slot_id) REFERENCES training_slot (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_slot_registration');
        $this->addSql('ALTER TABLE training_slot DROP registration_required, DROP capacity');
        $this->addSql('ALTER TABLE training_slot_template DROP registration_required, DROP capacity');
    }
}
