<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UserMessage : table de messages mobile → club / entraîneur, avec
 * réponse unique (verrouillée après écriture côté applicatif).
 */
final class Version20260604210330 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UserMessage : messages mobile → club / entraîneur + réponse unique';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE user_message (
                id INT AUTO_INCREMENT NOT NULL,
                sender_id INT NOT NULL,
                recipient_id INT DEFAULT NULL,
                replied_by_id INT DEFAULT NULL,
                subject VARCHAR(200) DEFAULT NULL,
                body LONGTEXT NOT NULL,
                sent_at DATETIME NOT NULL,
                reply LONGTEXT DEFAULT NULL,
                replied_at DATETIME DEFAULT NULL,
                INDEX idx_user_message_sender (sender_id),
                INDEX idx_user_message_recipient (recipient_id),
                INDEX idx_user_message_replied_by (replied_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE user_message ADD CONSTRAINT fk_user_message_sender FOREIGN KEY (sender_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_message ADD CONSTRAINT fk_user_message_recipient FOREIGN KEY (recipient_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_message ADD CONSTRAINT fk_user_message_replied_by FOREIGN KEY (replied_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_message');
    }
}
