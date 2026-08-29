<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ArticleAttachment : pièces jointes aux articles (PDF, GPX, docs…).
 * Miroir de training_slot_attachment.
 */
final class Version20260829091034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ArticleAttachment : table de pièces jointes par article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE article_attachment (
                id INT AUTO_INCREMENT NOT NULL,
                article_id INT NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                size INT NOT NULL,
                uploaded_at DATETIME NOT NULL,
                INDEX idx_article_attachment_article (article_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE article_attachment ADD CONSTRAINT fk_article_attachment_article FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_attachment');
    }
}
