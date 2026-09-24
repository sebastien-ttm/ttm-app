<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réglages d'accès à la bourse aux équipements (fermée / testeurs /
 * tout le club), modifiables depuis le backend. Aucune ligne créée :
 * tant que l'admin n'a rien enregistré, la variable d'environnement
 * MARKETPLACE_TESTER_EMAILS continue de s'appliquer.
 */
final class Version20260924030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée marketplace_settings (accès à la bourse aux équipements).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_settings (
            id INT AUTO_INCREMENT NOT NULL,
            mode VARCHAR(16) DEFAULT \'testers\' NOT NULL,
            tester_emails LONGTEXT DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_settings');
    }
}
