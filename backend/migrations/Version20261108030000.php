<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Événements : lien vers un formulaire HelloAsso (boutique, adhésion, billetterie) pour vérifier les paiements.
 */
final class Version20261108030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute event.helloasso_form_url (formulaire HelloAsso de vérification des paiements).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD helloasso_form_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP helloasso_form_url');
    }
}
