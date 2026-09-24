<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bourse aux équipements (onglet Club) : annonces d'adhérents pour du
 * matériel/des affaires d'occasion, avec jusqu'à 5 photos chacune.
 */
final class Version20260924010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée marketplace_listing + marketplace_listing_photo (bourse aux équipements).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_listing (
            id INT AUTO_INCREMENT NOT NULL,
            author_id INT NOT NULL,
            title VARCHAR(120) NOT NULL,
            description LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            paused_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_marketplace_listing_author (author_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_ML_AUTHOR FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE marketplace_listing_photo (
            id INT AUTO_INCREMENT NOT NULL,
            listing_id INT NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            position INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_marketplace_photo_listing (listing_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_MLP_LISTING FOREIGN KEY (listing_id) REFERENCES marketplace_listing (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing_photo');
        $this->addSql('DROP TABLE marketplace_listing');
    }
}
