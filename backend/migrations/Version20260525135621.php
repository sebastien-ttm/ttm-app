<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User.profiles : nouvelle colonne JSON listant les profils.
 * Initialisée depuis la valeur actuelle de categorie (jeune ou senior),
 * et complétée avec ROLE_COACH/ROLE_ADMIN si présents dans roles.
 */
final class Version20260525135621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : colonne profiles[] + initialisation depuis categorie';
    }

    public function up(Schema $schema): void
    {
        // Ajout de la colonne (JSON NOT NULL avec '[]' par défaut)
        $this->addSql("ALTER TABLE `user` ADD profiles JSON NOT NULL");

        // Initialise profiles avec la categorie courante (Jeune ou Senior)
        $this->addSql("UPDATE `user` SET profiles = JSON_ARRAY(categorie)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP profiles');
    }
}
