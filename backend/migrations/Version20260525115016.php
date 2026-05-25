<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Déplace la configuration "anciens adhérents valides jusqu'au" depuis
 * training_season vers une nouvelle table dédiée membership_settings.
 * Plus cohérent côté UX : c'est de la gestion d'adhésion, pas d'entraînement.
 */
final class Version20260525115016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nouvelle table membership_settings (déplacé depuis training_season)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE membership_settings (
            id INT AUTO_INCREMENT NOT NULL,
            old_members_valid_until DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // Migre la valeur existante depuis training_season (s'il y en a une)
        $this->addSql("INSERT INTO membership_settings (old_members_valid_until)
                       SELECT old_members_valid_until FROM training_season
                       WHERE old_members_valid_until IS NOT NULL
                       ORDER BY id ASC LIMIT 1");

        // Si rien n'a été migré, crée quand même une ligne vide pour avoir le singleton
        $this->addSql("INSERT INTO membership_settings (old_members_valid_until)
                       SELECT NULL FROM DUAL
                       WHERE NOT EXISTS (SELECT 1 FROM membership_settings)");

        $this->addSql("ALTER TABLE training_season DROP old_members_valid_until");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE training_season ADD old_members_valid_until DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)'");
        $this->addSql("UPDATE training_season SET old_members_valid_until = (SELECT old_members_valid_until FROM membership_settings ORDER BY id ASC LIMIT 1) WHERE id = (SELECT MIN(id) FROM training_season)");
        $this->addSql('DROP TABLE membership_settings');
    }
}
