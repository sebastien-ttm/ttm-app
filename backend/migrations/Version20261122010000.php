<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compétitions proposées : la mise en pause est supprimée (la modération avant publication la remplace). Les compétitions
 * qui étaient « en pause » redeviennent « validées » (publiées, ouvertes aux réponses) et leur message de pause — qui
 * était visible de tous — est effacé.
 *
 * À exécuter AVANT de déployer le nouveau code : il ne connaît plus le statut « paused » et ne pourrait plus lire ces lignes.
 */
final class Version20261122010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'race_proposal : les compétitions en pause redeviennent validées (la mise en pause est supprimée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE race_proposal SET status = 'validated', moderation_note = NULL WHERE status = 'paused'");
    }

    public function down(Schema $schema): void
    {
        // Irréversible : on ne sait plus quelles compétitions étaient en pause. Le statut « paused » n'existe plus dans le code.
    }
}
