<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entraînements sur inscription, suite :
 *  - une semaine peut être « débloquée » par un entraîneur (2 inscriptions par adhérent au lieu d'une) : table
 *    training_registration_unlock, et rang (`position`) sur chaque inscription — l'unicité (user, semaine, rang)
 *    remplace l'unicité (user, semaine) ;
 *  - émargement des entraînements sur inscription depuis l'espace Staff : table training_slot_check_in.
 */
final class Version20261013010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inscriptions aux entraînements : déblocage hebdomadaire (position, training_registration_unlock) + émargements (training_slot_check_in).';
    }

    public function up(Schema $schema): void
    {
        // Les nouveaux index (qui commencent par user_id) sont créés AVANT de retirer l'ancien : la clé étrangère
        // sur user_id a besoin d'un index.
        $this->addSql('ALTER TABLE training_slot_registration ADD position SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_training_registration_user_week_position ON training_slot_registration (user_id, week_starts_at, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_training_registration_user_template_week ON training_slot_registration (user_id, template_id, week_starts_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_training_registration_user_slot ON training_slot_registration (user_id, slot_id)');
        $this->addSql('DROP INDEX uniq_training_registration_user_week ON training_slot_registration');

        $this->addSql(<<<'SQL'
            CREATE TABLE training_registration_unlock (
                id INT AUTO_INCREMENT NOT NULL,
                unlocked_by_id INT DEFAULT NULL,
                week_starts_at DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_TRAINING_UNLOCK_BY (unlocked_by_id),
                UNIQUE INDEX uniq_training_registration_unlock_week (week_starts_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_TRAINING_UNLOCK_BY FOREIGN KEY (unlocked_by_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE training_slot_check_in (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                template_id INT DEFAULT NULL,
                slot_id INT DEFAULT NULL,
                checked_by_id INT DEFAULT NULL,
                week_starts_at DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                checked_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_training_check_in_template_week (template_id, week_starts_at),
                INDEX idx_training_check_in_slot (slot_id),
                INDEX IDX_TRAINING_CHECK_IN_BY (checked_by_id),
                UNIQUE INDEX uniq_training_check_in_user_template_week (user_id, template_id, week_starts_at),
                UNIQUE INDEX uniq_training_check_in_user_slot (user_id, slot_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_TRAINING_CHECK_IN_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT FK_TRAINING_CHECK_IN_TEMPLATE FOREIGN KEY (template_id) REFERENCES training_slot_template (id) ON DELETE CASCADE,
                CONSTRAINT FK_TRAINING_CHECK_IN_SLOT FOREIGN KEY (slot_id) REFERENCES training_slot (id) ON DELETE CASCADE,
                CONSTRAINT FK_TRAINING_CHECK_IN_BY FOREIGN KEY (checked_by_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_slot_check_in');
        $this->addSql('DROP TABLE training_registration_unlock');
        // Retour à « une inscription par semaine » : impossible s'il existe déjà des secondes inscriptions.
        $this->addSql('CREATE UNIQUE INDEX uniq_training_registration_user_week ON training_slot_registration (user_id, week_starts_at)');
        $this->addSql('DROP INDEX uniq_training_registration_user_week_position ON training_slot_registration');
        $this->addSql('DROP INDEX uniq_training_registration_user_template_week ON training_slot_registration');
        $this->addSql('DROP INDEX uniq_training_registration_user_slot ON training_slot_registration');
        $this->addSql('ALTER TABLE training_slot_registration DROP position');
    }
}
