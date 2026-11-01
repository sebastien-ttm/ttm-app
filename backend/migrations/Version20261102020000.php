<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Photos du club : photo de couverture choisie pour un album (liste des albums) et son cadrage.
 */
final class Version20261102020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée photo_album_cover (photo de couverture et cadrage d\'un album Piwigo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE photo_album_cover (
                id INT AUTO_INCREMENT NOT NULL,
                piwigo_album_id INT NOT NULL,
                cover_image_id INT DEFAULT NULL,
                focus_x SMALLINT DEFAULT 50 NOT NULL,
                focus_y SMALLINT DEFAULT 50 NOT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_photo_album_cover_album (piwigo_album_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE photo_album_cover');
    }
}
