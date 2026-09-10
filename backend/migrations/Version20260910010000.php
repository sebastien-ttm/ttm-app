<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute event.external_registration_url pour les événements soumis
 * au vote dont l'inscription se fait sur une plateforme tierce
 * (Njuko / klikego / HelloAsso…). Quand renseignée, le bouton
 * « J'y serai » devient « Je m'inscris » côté mobile.
 */
final class Version20260910010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event.external_registration_url (inscription site tiers).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD external_registration_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP external_registration_url');
    }
}
