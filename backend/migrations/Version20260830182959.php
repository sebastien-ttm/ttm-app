<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute une colonne `kind` (all|new|renewal) à welcome_email_template
 * et club_charter pour distinguer les contenus destinés à un nouvel
 * adhérent vs un renouvellement.
 *
 * Valeur par défaut 'all' : les entrées existantes s'appliquent aux
 * deux cas (rétro-compat totale).
 */
final class Version20260830182959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'welcome_email_template + club_charter : colonne kind (all|new|renewal)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE welcome_email_template ADD kind VARCHAR(16) NOT NULL DEFAULT 'all'");
        $this->addSql("ALTER TABLE club_charter ADD kind VARCHAR(16) NOT NULL DEFAULT 'all'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE welcome_email_template DROP kind');
        $this->addSql('ALTER TABLE club_charter DROP kind');
    }
}
