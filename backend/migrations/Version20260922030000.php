<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Permet à l'auteur d'un commentaire de le modifier après publication.
 * `edited_at` reste NULL tant que le commentaire n'a jamais été édité
 * — sert d'indicateur « (modifié) » côté mobile.
 */
final class Version20260922030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comment : ajoute edited_at (édition du commentaire par son auteur).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment ADD edited_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment DROP edited_at');
    }
}
