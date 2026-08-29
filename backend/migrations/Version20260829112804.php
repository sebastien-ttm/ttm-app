<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TrainingPlan.publishedAt : programme la mise en ligne d'un plan.
 * NULL = publication immédiate ; sinon, le plan reste masqué de l'API mobile
 * et les notifications sont différées jusqu'à cette date.
 */
final class Version20260829112804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TrainingPlan : ajout de publishedAt (programmation de la mise en ligne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_plan ADD published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        // Rétro-compat : les plans existants sont considérés publiés depuis leur posted_at.
        $this->addSql('UPDATE training_plan SET published_at = posted_at WHERE published_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_plan DROP published_at');
    }
}
