<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pièces jointes (PDF, documents…) sur les pages statiques.
 */
final class Version20261009020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée static_page_attachment (pièces jointes des pages statiques).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE static_page_attachment (
            id INT AUTO_INCREMENT NOT NULL,
            page_id INT NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size INT NOT NULL,
            uploaded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_static_page_attachment_page (page_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_SPA_PAGE FOREIGN KEY (page_id) REFERENCES static_page (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE static_page_attachment');
    }
}
