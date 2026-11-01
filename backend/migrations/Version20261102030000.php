<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photos du club : quand un membre du staff publie des photos au nom d'un adhérent, user_id reste l'auteur affiché
 * et uploaded_by_id garde celui qui les a réellement envoyées (suppression, modération).
 */
final class Version20261102030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute photo_upload.uploaded_by_id (qui a envoyé la photo quand elle est publiée au nom d\'un autre adhérent).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo_upload
            ADD uploaded_by_id INT DEFAULT NULL,
            ADD CONSTRAINT FK_PHOTO_UPLOAD_UPLOADED_BY FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photo_upload DROP FOREIGN KEY FK_PHOTO_UPLOAD_UPLOADED_BY');
        $this->addSql('ALTER TABLE photo_upload DROP COLUMN uploaded_by_id');
    }
}
