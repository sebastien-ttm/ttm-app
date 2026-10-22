<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stages : table stage, participants (stage_participant) et lien optionnel stage_id sur les éléments dédiés à un
 * stage (événements, articles, sondages, prises de temps, entraînements). RESTRICT : un stage qui a encore des
 * éléments dédiés ne se supprime pas (ils deviendraient publics).
 */
final class Version20261023010000 extends AbstractMigration
{
    /** Tables qui reçoivent un lien `stage_id` (préfixe court pour nommer index et clé étrangère). */
    private const DEDICATED = [
        'event' => 'event',
        'article' => 'article',
        'survey' => 'survey',
        'perf_test_session' => 'perf_session',
        'training_slot' => 'training_slot',
    ];

    public function getDescription(): string
    {
        return 'stage, stage_participant et stage_id sur event, article, survey, perf_test_session, training_slot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE stage (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(150) NOT NULL,
                icon VARCHAR(32) DEFAULT NULL,
                location VARCHAR(200) DEFAULT NULL,
                starts_on DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                ends_on DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                description LONGTEXT DEFAULT NULL,
                published TINYINT(1) NOT NULL,
                notified_user_ids JSON NOT NULL DEFAULT \'[]\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('
            CREATE TABLE stage_participant (
                stage_id INT NOT NULL,
                user_id INT NOT NULL,
                INDEX idx_stage_participant_stage (stage_id),
                INDEX idx_stage_participant_user (user_id),
                PRIMARY KEY(stage_id, user_id),
                CONSTRAINT fk_stage_participant_stage FOREIGN KEY (stage_id) REFERENCES stage (id) ON DELETE CASCADE,
                CONSTRAINT fk_stage_participant_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        foreach (self::DEDICATED as $table => $short) {
            $this->addSql(sprintf('ALTER TABLE %s ADD stage_id INT DEFAULT NULL', $table));
            $this->addSql(sprintf('CREATE INDEX idx_%s_stage ON %s (stage_id)', $short, $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT fk_%s_stage FOREIGN KEY (stage_id) REFERENCES stage (id) ON DELETE RESTRICT', $table, $short));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::DEDICATED as $table => $short) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY fk_%s_stage', $table, $short));
            $this->addSql(sprintf('DROP INDEX idx_%s_stage ON %s', $short, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP stage_id', $table));
        }
        $this->addSql('DROP TABLE stage_participant');
        $this->addSql('DROP TABLE stage');
    }
}
