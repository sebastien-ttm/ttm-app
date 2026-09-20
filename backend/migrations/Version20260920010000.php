<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refonte de la typologie des événements calendrier : remplace l'enum
 * codé en dur `event.type` par une entité `EventTag` configurable
 * (nom + couleur + position + active) reliée à `Event` en M2M.
 *
 * L'ancienne valeur `event.type` est PERDUE — l'utilisateur (produit)
 * a explicitement demandé à repartir de zéro pour la classification.
 * Une liste de 7 tags par défaut est insérée pour ne pas laisser le
 * sélecteur vide au premier chargement (Stage, Compétition, Cohésion,
 * Bénévolat, Journée, Tenues, Informations). Ces tags peuvent être
 * renommés / supprimés / réordonnés depuis l'admin.
 */
final class Version20260920010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passe event.type → EventTag (M2M configurable) + seed 7 tags par défaut.';
    }

    public function up(Schema $schema): void
    {
        // 1) Table event_tag
        $this->addSql('CREATE TABLE event_tag (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(60) NOT NULL,
            color VARCHAR(9) NOT NULL,
            position INT DEFAULT 100 NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 2) Table de jointure event ↔ event_tag
        $this->addSql('CREATE TABLE event_event_tag (
            event_id INT NOT NULL,
            event_tag_id INT NOT NULL,
            INDEX IDX_EET_EVENT (event_id),
            INDEX IDX_EET_TAG (event_tag_id),
            PRIMARY KEY(event_id, event_tag_id),
            CONSTRAINT FK_EET_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE,
            CONSTRAINT FK_EET_TAG FOREIGN KEY (event_tag_id) REFERENCES event_tag (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // 3) Seed 7 tags par défaut — l'admin peut les modifier / supprimer.
        $seed = [
            ['Stage',        '#1976D2', 10],
            ['Compétition',  '#D32F2F', 20],
            ['Cohésion',     '#00838F', 30],
            ['Bénévolat',    '#F57C00', 40],
            ['Journée',      '#5E35B1', 50],
            ['Tenues',       '#5D4037', 60],
            ['Informations', '#455A64', 70],
        ];
        foreach ($seed as [$name, $color, $position]) {
            $this->addSql(
                'INSERT INTO event_tag (name, color, position, active) VALUES (:name, :color, :position, 1)',
                ['name' => $name, 'color' => $color, 'position' => $position],
            );
        }

        // 4) Efface l'ancienne colonne event.type — les valeurs
        //    historiques ne sont PAS migrées (choix produit).
        $this->addSql('ALTER TABLE event DROP COLUMN type');
    }

    public function down(Schema $schema): void
    {
        // Restaure la colonne type (valeur par défaut de l'ancien enum)
        // et supprime les tables du nouveau modèle. Les liaisons M2M
        // sont perdues — le down ne remet PAS les anciens types (info
        // définitivement perdue à l'up).
        $this->addSql("ALTER TABLE event ADD type VARCHAR(16) DEFAULT 'entrainement' NOT NULL");
        $this->addSql('DROP TABLE event_event_tag');
        $this->addSql('DROP TABLE event_tag');
    }
}
