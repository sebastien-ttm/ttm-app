<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Période de grâce pour les anciens adhérents en début de saison.
 * Tant que today <= old_members_valid_until, l'import CSV ne désactive pas
 * les adhérents absents (ils ont le temps de renouveler).
 */
final class Version20260525113811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'training_season : période de grâce des anciens adhérents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE training_season ADD old_members_valid_until DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_season DROP old_members_valid_until');
    }
}
