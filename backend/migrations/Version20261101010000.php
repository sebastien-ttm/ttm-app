<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durée de vie des groupes d'entraînement : 8 heures après le DÉBUT de la séance (au lieu du début du créneau suivant).
 * Les groupes déjà créés, adossés à une séance et pas encore terminés, adoptent la même règle.
 */
final class Version20261101010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'training_group : fin de validité = début de la séance + 8 h.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            UPDATE training_group
            SET expires_at = DATE_ADD(session_starts_at, INTERVAL 8 HOUR)
            WHERE closed_at IS NULL
              AND session_starts_at IS NOT NULL
              AND (slot_template_id IS NOT NULL OR slot_id IS NOT NULL)
        ');
    }

    public function down(Schema $schema): void
    {
        // Les anciennes dates de fin (début du créneau suivant) ne sont pas conservées : rien à restaurer.
    }
}
