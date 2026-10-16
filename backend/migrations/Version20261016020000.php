<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messages modifiables par leur auteur : date de dernière modification (null = jamais modifié) pour les messages de
 * la bourse, les messages de Contact (texte/objet et 1re réponse) et la suite de conversation.
 */
final class Version20261016020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messages modifiables : edited_at (marketplace_message, message_reply, user_message) + reply_edited_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE marketplace_message ADD edited_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE message_reply ADD edited_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE user_message ADD edited_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD reply_edited_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_message DROP edited_at');
        $this->addSql('ALTER TABLE message_reply DROP edited_at');
        $this->addSql('ALTER TABLE user_message DROP edited_at, DROP reply_edited_at');
    }
}
