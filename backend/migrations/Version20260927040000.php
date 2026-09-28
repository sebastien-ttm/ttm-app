<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photos du club (galerie Piwigo) : trace des photos envoyées depuis
 * l'appli (auteur, album) pour l'affichage, la suppression par l'auteur
 * et la modération.
 */
final class Version20260927040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée photo_upload (photos envoyées dans Piwigo depuis l\'appli).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE photo_upload (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            piwigo_image_id INT NOT NULL,
            piwigo_album_id INT NOT NULL,
            album_name VARCHAR(255) NOT NULL,
            piwigo_url VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_photo_upload_piwigo_image (piwigo_image_id),
            INDEX idx_photo_upload_user_created (user_id, created_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_PHOTO_UPLOAD_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE photo_upload');
    }
}
