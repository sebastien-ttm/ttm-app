<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bourse aux équipements : discussions in-app entre un acheteur
 * potentiel et le vendeur (remplace le contact WhatsApp).
 */
final class Version20260924040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée marketplace_conversation + marketplace_message (discussions de la bourse).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_conversation (
            id INT AUTO_INCREMENT NOT NULL,
            listing_id INT NOT NULL,
            buyer_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_message_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_mp_conversation_listing_buyer (listing_id, buyer_id),
            INDEX idx_mp_conversation_buyer (buyer_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_MPC_LISTING FOREIGN KEY (listing_id) REFERENCES marketplace_listing (id) ON DELETE CASCADE,
            CONSTRAINT FK_MPC_BUYER FOREIGN KEY (buyer_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE marketplace_message (
            id INT AUTO_INCREMENT NOT NULL,
            conversation_id INT NOT NULL,
            author_id INT NOT NULL,
            content LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            notified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_mp_message_conversation (conversation_id),
            INDEX idx_mp_message_author_created (author_id, created_at),
            PRIMARY KEY(id),
            CONSTRAINT FK_MPM_CONVERSATION FOREIGN KEY (conversation_id) REFERENCES marketplace_conversation (id) ON DELETE CASCADE,
            CONSTRAINT FK_MPM_AUTHOR FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_message');
        $this->addSql('DROP TABLE marketplace_conversation');
    }
}
