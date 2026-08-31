<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute board_role et club_function sur user :
 *  - board_role : rôle CoDir (nullable) — président / trésorier /
 *    secrétaire / membre_codir. Structure la page trombinoscope Comité.
 *  - club_function : fonction libre au club (nullable) — utilisée à
 *    l'affichage pour les entraîneurs et les membres du CoDir.
 */
final class Version20260831100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add board_role and club_function on user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD board_role VARCHAR(32) DEFAULT NULL, ADD club_function VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP board_role, DROP club_function');
    }
}
