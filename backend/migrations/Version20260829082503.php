<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TrainingSlotTemplate : ajout season_id + backfill best-effort.
 *
 * Chaque template peut désormais être rattaché à une TrainingSeason.
 * Backfill : pour chaque template, on cherche une saison dont la plage
 * de dates contient (ou croise raisonnablement) le startsAt du template.
 * Les templates sans startsAt (legacy « toujours actif ») restent NULL —
 * à l'admin de les rattacher manuellement s'il veut.
 */
final class Version20260829082503 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TrainingSlotTemplate : ajout season_id (nullable) + backfill par match de dates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_slot_template ADD season_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE training_slot_template ADD CONSTRAINT fk_training_slot_template_season FOREIGN KEY (season_id) REFERENCES training_season (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_training_slot_template_season ON training_slot_template (season_id)');

        // Backfill : associe chaque template à la saison qui contient son
        // starts_at (le plus fiable). Si starts_at est NULL mais ends_at
        // est renseigné, on tente aussi via ends_at.
        // Templates sans dates : restent NULL, à rattacher manuellement
        // depuis le CRUD.
        // Sous-requête + LIMIT 1 pour être safe si plusieurs saisons
        // matchent — on prend la plus récente (starts_at DESC).
        $this->addSql("
            UPDATE training_slot_template t
            SET t.season_id = (
                SELECT s.id FROM training_season s
                WHERE (
                    (t.starts_at IS NOT NULL
                        AND (s.starts_at IS NULL OR s.starts_at <= t.starts_at)
                        AND (s.ends_at IS NULL OR s.ends_at >= t.starts_at))
                    OR
                    (t.starts_at IS NULL AND t.ends_at IS NOT NULL
                        AND (s.starts_at IS NULL OR s.starts_at <= t.ends_at)
                        AND (s.ends_at IS NULL OR s.ends_at >= t.ends_at))
                )
                ORDER BY s.starts_at DESC
                LIMIT 1
            )
            WHERE t.season_id IS NULL
              AND (t.starts_at IS NOT NULL OR t.ends_at IS NOT NULL)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_slot_template DROP FOREIGN KEY fk_training_slot_template_season');
        $this->addSql('DROP INDEX idx_training_slot_template_season ON training_slot_template');
        $this->addSql('ALTER TABLE training_slot_template DROP season_id');
    }
}
