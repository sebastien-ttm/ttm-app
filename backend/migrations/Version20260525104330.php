<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la table training_season (saison globale) et les dates
 * optionnelles starts_at/ends_at par créneau de la semaine type.
 */
final class Version20260525104330 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : saison globale + dates par créneau de la semaine type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE training_season (id INT AUTO_INCREMENT NOT NULL, starts_at DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', ends_at DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("ALTER TABLE training_slot_template ADD starts_at DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', ADD ends_at DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_season');
        $this->addSql('ALTER TABLE training_slot_template DROP starts_at, DROP ends_at');
    }
}
