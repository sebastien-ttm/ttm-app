<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compte rendu du CoDir : le signataire (le Secrétaire) est figé à la clôture de la réunion — nom, rôle et copie de sa
 * signature — pour qu'un compte rendu rouvert plus tard ne change pas si le Secrétaire change. NULL = pas encore figé.
 *
 * Additive : une colonne nullable. À exécuter AVANT de déployer le nouveau code (qui lit cette colonne).
 */
final class Version20261122030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'board_meeting : minutes_signers (signataire du compte rendu figé à la clôture).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_meeting ADD minutes_signers JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_meeting DROP minutes_signers');
    }
}
