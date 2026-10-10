<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Séance de test chronométré sur plusieurs dates (ex : 2 soirs).
 */
final class Version20261010010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute perf_test_session.extra_dates (autres dates d\'une même séance de test).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE perf_test_session ADD extra_dates JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE perf_test_session DROP extra_dates');
    }
}
