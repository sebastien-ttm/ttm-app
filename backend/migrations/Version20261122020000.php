<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Signature manuscrite d'un compte (image), insérée au bas du compte rendu du CoDir quand la personne est Secrétaire.
 * Additive : une colonne nullable. À exécuter AVANT de déployer le nouveau code (qui lit cette colonne).
 */
final class Version20261122020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user : signature_filename (signature du secrétaire pour le compte rendu du CoDir).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD signature_filename VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP signature_filename');
    }
}
