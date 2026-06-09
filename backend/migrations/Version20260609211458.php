<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User : préférence opt-in « recevoir un email à la publication d'un
 * plan d'entraînement ». Défaut FALSE (les adhérents existants ne
 * reçoivent plus les emails plan jusqu'à ce qu'ils cochent la case
 * dans leur profil mobile).
 */
final class Version20260609211458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : ajout notify_training_plan_email (opt-in défaut FALSE)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD notify_training_plan_email TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP notify_training_plan_email');
    }
}
