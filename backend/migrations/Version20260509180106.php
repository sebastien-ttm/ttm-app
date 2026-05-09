<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260509180106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, author_id INT NOT NULL, title VARCHAR(200) NOT NULL, content LONGTEXT NOT NULL, published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', notify_on_publish TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_23A0E66F675F31B (author_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE article_photo (id INT AUTO_INCREMENT NOT NULL, article_id INT NOT NULL, file_path VARCHAR(255) DEFAULT NULL, position INT NOT NULL, alt VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_6300F6097294869C (article_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE banner (id INT AUTO_INCREMENT NOT NULL, image_path VARCHAR(255) DEFAULT NULL, title VARCHAR(200) DEFAULT NULL, link_url VARCHAR(500) DEFAULT NULL, starts_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_active TINYINT(1) NOT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE comment (id INT AUTO_INCREMENT NOT NULL, article_id INT NOT NULL, user_id INT NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_9474526C7294869C (article_id), INDEX IDX_9474526CA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE device_token (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, expo_push_token VARCHAR(255) NOT NULL, platform VARCHAR(16) NOT NULL, last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_99B2415CA76ED395 (user_id), UNIQUE INDEX uniq_device_token (expo_push_token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE event (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, starts_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', type VARCHAR(16) NOT NULL, color VARCHAR(7) DEFAULT NULL, INDEX idx_event_starts_at (starts_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE magic_link_token (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_E08FED56B3BC57DA (token_hash), INDEX IDX_E08FED56A76ED395 (user_id), INDEX idx_magic_link_token_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE menu_item (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(60) NOT NULL, type VARCHAR(16) NOT NULL, target VARCHAR(255) DEFAULT NULL, icon VARCHAR(60) DEFAULT NULL, position INT NOT NULL, is_visible TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reaction (id INT AUTO_INCREMENT NOT NULL, article_id INT NOT NULL, user_id INT NOT NULL, emoji VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_A4D707F7A76ED395 (user_id), INDEX idx_reaction_article (article_id), UNIQUE INDEX uniq_reaction_article_user_emoji (article_id, user_id, emoji), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE refresh_token (id INT AUTO_INCREMENT NOT NULL, refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, UNIQUE INDEX UNIQ_C74F2195C74F2195 (refresh_token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE static_page (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(200) NOT NULL, content LONGTEXT NOT NULL, is_published TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_static_page_slug (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE training_plan (id INT AUTO_INCREMENT NOT NULL, posted_by_id INT NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, file_path VARCHAR(255) DEFAULT NULL, week_starts_at DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', posted_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_D2C01C3E5A6D2235 (posted_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, num_licence VARCHAR(32) NOT NULL, nom VARCHAR(120) NOT NULL, prenom VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, categorie VARCHAR(16) NOT NULL, statut_licence VARCHAR(40) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) NOT NULL, last_csv_sync_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_user_num_licence (num_licence), UNIQUE INDEX uniq_user_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE article_photo ADD CONSTRAINT FK_6300F6097294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE device_token ADD CONSTRAINT FK_99B2415CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE magic_link_token ADD CONSTRAINT FK_E08FED56A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F77294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_plan ADD CONSTRAINT FK_D2C01C3E5A6D2235 FOREIGN KEY (posted_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66F675F31B');
        $this->addSql('ALTER TABLE article_photo DROP FOREIGN KEY FK_6300F6097294869C');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526C7294869C');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_9474526CA76ED395');
        $this->addSql('ALTER TABLE device_token DROP FOREIGN KEY FK_99B2415CA76ED395');
        $this->addSql('ALTER TABLE magic_link_token DROP FOREIGN KEY FK_E08FED56A76ED395');
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F77294869C');
        $this->addSql('ALTER TABLE reaction DROP FOREIGN KEY FK_A4D707F7A76ED395');
        $this->addSql('ALTER TABLE training_plan DROP FOREIGN KEY FK_D2C01C3E5A6D2235');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE article_photo');
        $this->addSql('DROP TABLE banner');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE device_token');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE magic_link_token');
        $this->addSql('DROP TABLE menu_item');
        $this->addSql('DROP TABLE reaction');
        $this->addSql('DROP TABLE refresh_token');
        $this->addSql('DROP TABLE static_page');
        $this->addSql('DROP TABLE training_plan');
        $this->addSql('DROP TABLE `user`');
    }
}
