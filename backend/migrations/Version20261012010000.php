<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Articles lus par adhérent : permet de présenter autrement, dans la liste « Actualités » de l'appli,
 * les articles déjà lus. 1 ligne par couple (user, article) ; l'index unique évite les doublons.
 */
final class Version20261012010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée article_read (articles lus, une ligne par user/article).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE article_read (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                article_id INT NOT NULL,
                read_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_article_read_article (article_id),
                UNIQUE INDEX uniq_article_read_user_article (user_id, article_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_ARTICLE_READ_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT FK_ARTICLE_READ_ARTICLE FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_read');
    }
}
