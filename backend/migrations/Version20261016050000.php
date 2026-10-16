<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messagerie : suppression par l'auteur. Effacement logique (deleted_at) : le message disparaît de l'application pour
 * tout le monde mais reste visible des admins dans le backend, qui conserve l'historique.
 */
final class Version20261016050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_message.deleted_at et message_reply.deleted_at (messages supprimés par leur auteur).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_message ADD deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE message_reply ADD deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message DROP deleted_at');
        $this->addSql('ALTER TABLE message_reply DROP deleted_at');
    }
}
