<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510102654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE static_page ADD parent_id INT DEFAULT NULL, ADD position INT NOT NULL');
        $this->addSql('ALTER TABLE static_page ADD CONSTRAINT FK_8FA4EF95727ACA70 FOREIGN KEY (parent_id) REFERENCES static_page (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8FA4EF95727ACA70 ON static_page (parent_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE static_page DROP FOREIGN KEY FK_8FA4EF95727ACA70');
        $this->addSql('DROP INDEX IDX_8FA4EF95727ACA70 ON static_page');
        $this->addSql('ALTER TABLE static_page DROP parent_id, DROP position');
    }
}
