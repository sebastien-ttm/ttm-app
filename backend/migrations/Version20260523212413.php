<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523212413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP INDEX uniq_user_email, ADD INDEX idx_user_email (email)');
        $this->addSql('ALTER TABLE user ADD linked_to_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649BE323527 FOREIGN KEY (linked_to_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649BE323527 ON user (linked_to_user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` DROP INDEX idx_user_email, ADD UNIQUE INDEX uniq_user_email (email)');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649BE323527');
        $this->addSql('DROP INDEX IDX_8D93D649BE323527 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP linked_to_user_id');
    }
}
