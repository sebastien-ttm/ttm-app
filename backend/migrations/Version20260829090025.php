<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * StaffWeekUnavailability : déclaration explicite « je ne suis pas dispo
 * cette semaine » pour un encadrant / entraîneur. Permet de distinguer
 * « pas dispo » de « pas encore répondu » dans la supervision.
 */
final class Version20260829090025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'StaffWeekUnavailability : marqueur non-dispo hebdomadaire par staff';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE staff_week_unavailability (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                week_starts_at DATE NOT NULL,
                notes VARCHAR(200) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_staff_week_unav_week (week_starts_at),
                UNIQUE INDEX uniq_staff_week_unav_user_week (user_id, week_starts_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE staff_week_unavailability ADD CONSTRAINT fk_staff_week_unav_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE staff_week_unavailability');
    }
}
