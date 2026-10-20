<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Documents officiels des adhérents jeunes : fiche sanitaire et autorisation de sortie du territoire (un document par jeune
 * et par type), déposés par le jeune, ses parents ou le staff.
 */
final class Version20261020040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'youth_document : fiche sanitaire et autorisation de sortie du territoire des jeunes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE youth_document (
                id INT AUTO_INCREMENT NOT NULL,
                owner_id INT NOT NULL,
                uploaded_by_id INT DEFAULT NULL,
                type VARCHAR(24) NOT NULL,
                stored_name VARCHAR(64) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                size INT NOT NULL,
                uploaded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                valid_until DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_youth_document_owner_type (owner_id, type),
                INDEX idx_youth_document_uploaded_by (uploaded_by_id),
                CONSTRAINT fk_youth_document_owner FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE,
                CONSTRAINT fk_youth_document_uploader FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE youth_document');
    }
}
