<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messagerie : suivi « lu » — un message dont on a ouvert le détail ne compte plus dans la pastille de l'onglet
 * Social, sans attendre qu'il soit archivé.
 */
final class Version20261016040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_message.sender_read_at et user_message_recipient_state.read_at (messages lus).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_message ADD sender_read_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE user_message_recipient_state ADD read_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message DROP sender_read_at');
        $this->addSql('ALTER TABLE user_message_recipient_state DROP read_at');
    }
}
