<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Courses proposées : texte libre de description et, pour un capitaine, date limite de réponse avant l'inscription
 * groupée.
 */
final class Version20261016030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'race_proposal : description (texte libre) et registration_deadline (date limite de réponse).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE race_proposal ADD description LONGTEXT DEFAULT NULL, ADD registration_deadline DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race_proposal DROP description, DROP registration_deadline');
    }
}
