<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TrainingSeason : ajout name (nullable) pour affichage humain
 * (à la place du fallback « #id »).
 */
final class Version20260829083316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TrainingSeason : ajout name (libellé humain)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_season ADD name VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_season DROP name');
    }
}
