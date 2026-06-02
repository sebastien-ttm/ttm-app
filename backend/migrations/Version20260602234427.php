<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table pool_badge : QR code piscines partagé par tous les adhérents,
 * uploadé par l'admin chaque saison.
 */
final class Version20260602234427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création table pool_badge (QR code piscines)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE pool_badge (
            id INT AUTO_INCREMENT NOT NULL,
            image_path VARCHAR(255) DEFAULT NULL,
            title VARCHAR(200) DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pool_badge');
    }
}
