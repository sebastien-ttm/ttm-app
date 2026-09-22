<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conversation illimitée sur les messages Contact : une fois le 1er
 * échange verrouillé (user_message.body → reply), la suite se stocke
 * dans message_reply — un tour par ligne, auteur explicite (sender OU
 * n'importe quel viewer éligible côté destinataire).
 */
final class Version20260922040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée message_reply (conversation illimitée après le 1er échange verrouillé).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE message_reply (
            id INT AUTO_INCREMENT NOT NULL,
            message_id INT NOT NULL,
            author_id INT NOT NULL,
            content LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            notified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_message_reply_message (message_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_MR_MESSAGE FOREIGN KEY (message_id) REFERENCES user_message (id) ON DELETE CASCADE,
            CONSTRAINT FK_MR_AUTHOR FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE message_reply');
    }
}
