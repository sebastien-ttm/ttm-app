<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Motif structuré (maladie / vacances / déplacement) sur les
 * déclarations d'indisponibilité du staff :
 *  - ajoute la colonne reason à staff_week_unavailability (existant)
 *  - crée staff_day_unavailability (nouveau : absence sur une seule
 *    journée, plutôt que la semaine entière)
 */
final class Version20260930020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le motif d\'absence (staff_week_unavailability) et crée staff_day_unavailability.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE staff_week_unavailability ADD reason VARCHAR(20) DEFAULT NULL');

        $this->addSql('CREATE TABLE staff_day_unavailability (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            reason VARCHAR(20) DEFAULT NULL,
            notes VARCHAR(200) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_staff_day_unav_user_date (user_id, date),
            INDEX idx_staff_day_unav_date (date),
            PRIMARY KEY(id),
            CONSTRAINT FK_SDU_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE staff_day_unavailability');
        $this->addSql('ALTER TABLE staff_week_unavailability DROP reason');
    }
}
