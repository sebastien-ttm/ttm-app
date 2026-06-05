<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Event :
 *  - ADD is_all_day BOOLEAN (événements sans heure significative).
 *  - DROP color : la couleur est désormais dérivée du type (palette
 *    club fixe, plus de color picker dans le CRUD).
 */
final class Version20260605161947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event : ajout is_all_day + suppression color (couleur dérivée du type)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD is_all_day TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE event DROP color');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP is_all_day');
        $this->addSql('ALTER TABLE event ADD color VARCHAR(7) DEFAULT NULL');
    }
}
