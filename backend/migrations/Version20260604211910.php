<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UserMessage : 2 horodatages d'idempotence pour les notifications email.
 *   - recipients_notified_at : email « nouveau message » envoyé aux admins
 *     ou à l'entraîneur ciblé.
 *   - sender_replied_notified_at : email à l'expéditeur quand la réponse
 *     est postée.
 */
final class Version20260604211910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UserMessage : horodatages d\'envoi des notifications email (idempotence)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message ADD recipients_notified_at DATETIME DEFAULT NULL, ADD sender_replied_notified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_message DROP recipients_notified_at, DROP sender_replied_notified_at');
    }
}
