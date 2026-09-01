<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rapatrie les engagements et ajoute le message final dans club_charter,
 * pour que le tunnel d'onboarding (message de bienvenue → engagements
 * un par un → message final) soit configurable dans un seul CRUD par
 * saison.
 *
 *  - ADD fields JSON : liste des engagements — versionnée par saison
 *    (recopie depuis le singleton charter_engagement_settings sur le
 *    charter le plus récent, laissé NULL sur les autres).
 *  - ADD final_message TEXT : message affiché à l'écran final du tunnel
 *    juste avant le bouton « Valider mon accès à l'application ».
 *  - DROP TABLE charter_engagement_settings : plus utilisé.
 */
final class Version20260901010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge engagements + final message into club_charter (single tunnel per season).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_charter ADD fields JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE club_charter ADD final_message LONGTEXT DEFAULT NULL');

        // Copie le fields du singleton (s'il existe) vers le charter le
        // plus récent en date de publication. Les charters plus anciens
        // gardent fields NULL — équivalent à « pas d'engagements pour
        // cette version ».
        $singleton = $this->connection->fetchAssociative(
            'SELECT fields FROM charter_engagement_settings ORDER BY id ASC LIMIT 1'
        );
        if ($singleton !== false && ($singleton['fields'] ?? null) !== null) {
            $latest = $this->connection->fetchAssociative(
                'SELECT id FROM club_charter ORDER BY published_at DESC LIMIT 1'
            );
            if ($latest !== false) {
                $this->addSql(
                    'UPDATE club_charter SET fields = :fields WHERE id = :id',
                    ['fields' => $singleton['fields'], 'id' => (int) $latest['id']],
                );
            }
        }

        $this->addSql('DROP TABLE charter_engagement_settings');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE charter_engagement_settings (
                id INT AUTO_INCREMENT NOT NULL,
                fields JSON DEFAULT NULL COMMENT \'(DC2Type:json)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        // Restaure best-effort — reprend fields du charter courant.
        $latest = $this->connection->fetchAssociative(
            'SELECT fields FROM club_charter WHERE fields IS NOT NULL ORDER BY published_at DESC LIMIT 1'
        );
        $fields = ($latest !== false && isset($latest['fields'])) ? $latest['fields'] : null;
        $this->addSql(
            'INSERT INTO charter_engagement_settings (fields, updated_at) VALUES (:fields, NOW())',
            ['fields' => $fields],
        );
        $this->addSql('ALTER TABLE club_charter DROP fields, DROP final_message');
    }
}
