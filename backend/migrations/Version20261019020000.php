<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adhésions : le mode de paiement est déduit de la part club à l'import CSV (0 € = réglé au club, sinon encaissé par la
 * FFTri). payment_type_manual mémorise qu'un admin l'a choisi lui-même, pour que l'import ne l'écrase pas.
 */
final class Version20261019020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_season_membership : payment_type_manual (mode de paiement choisi à la main).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership ADD payment_type_manual TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership DROP payment_type_manual');
    }
}
