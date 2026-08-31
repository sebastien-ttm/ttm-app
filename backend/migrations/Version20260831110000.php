<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Simplifie ClubCharter : suppression de la colonne `kind`.
 * Un seul formulaire d'acceptation actif pour tous les adhérents
 * (le plus récent en date), plus de branchement nouveau/renouvellement.
 * La page CRUD conserve l'historique par saison.
 *
 * WelcomeEmailTemplate garde son propre `kind` (email de bienvenue
 * distinct nouveau/renouvellement) — inchangé.
 */
final class Version20260831110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop kind column on club_charter (single form for all adherents).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_charter DROP kind');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE club_charter ADD kind VARCHAR(16) DEFAULT 'all' NOT NULL");
    }
}
