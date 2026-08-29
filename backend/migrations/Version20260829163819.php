<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GouterCancellation : marque un mercredi comme annulé (vacances scolaires,
 * compétition, jour férié…). Les positionnements existants restent mais
 * plus aucun nouveau n'est accepté sur cette date.
 */
final class Version20260829163819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GouterCancellation : annulation d\'un mercredi donné';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE gouter_cancellation (
                id INT AUTO_INCREMENT NOT NULL,
                cancelled_by_id INT DEFAULT NULL,
                cancelled_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                reason LONGTEXT DEFAULT NULL,
                cancelled_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_gouter_cancel_date (cancelled_date),
                INDEX IDX_gouter_cancel_by (cancelled_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE gouter_cancellation ADD CONSTRAINT fk_gouter_cancel_by FOREIGN KEY (cancelled_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gouter_cancellation');
    }
}
