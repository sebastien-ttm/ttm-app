<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la colonne `audience` (JSON NOT NULL) sur tous les contenus
 * filtrables par profil utilisateur. Initialisée à '[]' (= visible par tous).
 */
final class Version20260525141843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contenus : colonne audience[] pour ciblage par profil utilisateur';
    }

    public function up(Schema $schema): void
    {
        foreach (['article', 'training_slot', 'training_slot_template', 'static_page', 'event', 'training_plan'] as $table) {
            $this->addSql("ALTER TABLE `{$table}` ADD audience JSON NOT NULL DEFAULT '[]'");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['article', 'training_slot', 'training_slot_template', 'static_page', 'event', 'training_plan'] as $table) {
            $this->addSql("ALTER TABLE `{$table}` DROP audience");
        }
    }
}
