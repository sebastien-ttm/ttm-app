<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Permission indépendante du rôle ROLE_ENTRAINEUR : donne accès à la
 * page backend « Emploi du temps entraîneurs » à un sous-ensemble
 * d'entraîneurs seulement (+ les admins, via StaffScheduleSupervisionVoter).
 */
final class Version20260930040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute user.can_manage_trainer_schedule.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD can_manage_trainer_schedule TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP can_manage_trainer_schedule');
    }
}
