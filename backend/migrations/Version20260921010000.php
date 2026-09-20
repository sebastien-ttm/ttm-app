<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Traçage des ouvertures de PDF de programme d'entraînement — 1 ligne
 * par couple (user, plan). L'index unique garantit qu'un même adhérent
 * n'est jamais compté plusieurs fois sur un même programme, quel que
 * soit le nombre de clics.
 */
final class Version20260921010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée training_plan_open (ouvertures uniques par user/plan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_plan_open (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            plan_id INT NOT NULL,
            opened_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_plan_open_plan (plan_id),
            UNIQUE INDEX uniq_plan_open_user_plan (user_id, plan_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_TPO_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_TPO_PLAN FOREIGN KEY (plan_id) REFERENCES training_plan (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_plan_open');
    }
}
