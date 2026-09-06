<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messages v2.1 : catégorisation des messages (bug / idée / aide).
 * Colonne `category` sur user_message, défaut 'general' (=comportement
 * historique, composer libre). Les messages postés depuis les 3
 * nouveaux boutons dédiés côté mobile portent la catégorie choisie.
 */
final class Version20260906020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messages v2.1 : ajout user_message.category (general|bug|improvement|help_offer).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_message ADD category VARCHAR(20) DEFAULT 'general' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message DROP category');
    }
}
