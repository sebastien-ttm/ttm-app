<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute l'opt-in email par adhérent pour les nouvelles publications
 * d'articles + le marqueur d'idempotence emailsSentAt sur article.
 * Miroir exact du dispositif training-plan (notifyTrainingPlanEmail
 * + TrainingPlan.emailsSentAt).
 */
final class Version20260921020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email opt-in adhérent pour nouveaux articles (parité training plans).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD notify_article_email TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE article ADD emails_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP notify_article_email');
        $this->addSql('ALTER TABLE article DROP emails_sent_at');
    }
}
