<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TrainingPlan : ajout emails_sent_at (horodate la dispatch d'emails
 * de notification, pour idempotence en cas de retry Messenger).
 */
final class Version20260604173402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TrainingPlan : ajout emails_sent_at pour idempotence des emails de notification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_plan ADD emails_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_plan DROP emails_sent_at');
    }
}
