<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extrait les engagements du formulaire d'acceptation dans un singleton
 * `charter_engagement_settings` — partagés entre toutes les ClubCharter
 * (nouveaux / renouvellements / tous). Le contenu textuel reste par kind.
 *
 * Seed : reprend le `fields` du ClubCharter le plus récent avec des
 * engagements non-nulls (priorité au kind='all').
 */
final class Version20260830210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extract charter engagements into a singleton (shared across kinds).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE charter_engagement_settings (
                id INT AUTO_INCREMENT NOT NULL,
                fields JSON DEFAULT NULL COMMENT \'(DC2Type:json)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // Seed depuis le charter le plus « représentatif » en cours :
        // 1. Priorité au kind='all' avec fields non null
        // 2. Sinon n'importe quel charter avec fields non null
        // 3. Sinon insert vide (les engagements pourront être ajoutés côté admin)
        $row = $this->connection->fetchAssociative(
            "SELECT fields FROM club_charter
             WHERE fields IS NOT NULL AND kind = 'all'
             ORDER BY published_at DESC LIMIT 1"
        );
        if ($row === false) {
            $row = $this->connection->fetchAssociative(
                'SELECT fields FROM club_charter
                 WHERE fields IS NOT NULL
                 ORDER BY published_at DESC LIMIT 1'
            );
        }
        $fields = ($row !== false && isset($row['fields'])) ? $row['fields'] : null;

        $this->addSql(
            'INSERT INTO charter_engagement_settings (fields, updated_at) VALUES (:fields, NOW())',
            ['fields' => $fields],
        );

        $this->addSql('ALTER TABLE club_charter DROP fields');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_charter ADD fields JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');

        // Restaure les engagements du singleton dans les charters existants
        // (best effort — on écrase toutes les rows avec la même valeur).
        $row = $this->connection->fetchAssociative(
            'SELECT fields FROM charter_engagement_settings ORDER BY id ASC LIMIT 1'
        );
        if ($row !== false && $row['fields'] !== null) {
            $this->addSql(
                'UPDATE club_charter SET fields = :fields',
                ['fields' => $row['fields']],
            );
        }

        $this->addSql('DROP TABLE charter_engagement_settings');
    }
}
