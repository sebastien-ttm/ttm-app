<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510145914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE charter_acceptance (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, charter_id INT NOT NULL, accepted_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ip_address VARCHAR(45) DEFAULT NULL, INDEX IDX_6CF2505AA76ED395 (user_id), INDEX idx_charter_acceptance_charter (charter_id), UNIQUE INDEX uniq_charter_acceptance_user_charter (user_id, charter_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE club_charter (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, version VARCHAR(50) NOT NULL, content LONGTEXT NOT NULL, is_active TINYINT(1) NOT NULL, published_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE charter_acceptance ADD CONSTRAINT FK_6CF2505AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE charter_acceptance ADD CONSTRAINT FK_6CF2505AC0005641 FOREIGN KEY (charter_id) REFERENCES club_charter (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE charter_acceptance DROP FOREIGN KEY FK_6CF2505AA76ED395');
        $this->addSql('ALTER TABLE charter_acceptance DROP FOREIGN KEY FK_6CF2505AC0005641');
        $this->addSql('DROP TABLE charter_acceptance');
        $this->addSql('DROP TABLE club_charter');
    }
}
