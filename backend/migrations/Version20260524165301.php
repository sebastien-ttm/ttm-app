<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260524165301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE training_slot (id INT AUTO_INCREMENT NOT NULL, template_id INT DEFAULT NULL, week_starts_at DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', day_of_week SMALLINT NOT NULL, start_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', duration_minutes SMALLINT NOT NULL, sport VARCHAR(16) NOT NULL, title VARCHAR(120) NOT NULL, location VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, is_cancelled TINYINT(1) NOT NULL, INDEX IDX_A39467245DA0FB8 (template_id), INDEX idx_training_slot_week (week_starts_at), UNIQUE INDEX uniq_training_slot_week_template (week_starts_at, template_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE training_slot_template (id INT AUTO_INCREMENT NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME NOT NULL COMMENT \'(DC2Type:time_immutable)\', duration_minutes SMALLINT NOT NULL, sport VARCHAR(16) NOT NULL, title VARCHAR(120) NOT NULL, location VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, position SMALLINT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE training_slot ADD CONSTRAINT FK_A39467245DA0FB8 FOREIGN KEY (template_id) REFERENCES training_slot_template (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE training_slot DROP FOREIGN KEY FK_A39467245DA0FB8');
        $this->addSql('DROP TABLE training_slot');
        $this->addSql('DROP TABLE training_slot_template');
    }
}
