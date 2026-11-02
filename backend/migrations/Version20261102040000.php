<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compétitions proposées : une compétition peut durer plusieurs jours (plusieurs épreuves) — date de fin facultative,
 * null = une seule journée.
 */
final class Version20261102040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute race_proposal.race_end_date (dernier jour d\'une compétition sur plusieurs jours).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race_proposal ADD race_end_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race_proposal DROP COLUMN race_end_date');
    }
}
