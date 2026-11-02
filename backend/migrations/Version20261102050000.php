<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Documents des jeunes : l'autorisation de sortie du territoire n'est plus un document permanent, elle est rattachée à
 * la participation à un stage (stage_id). Un document par (jeune, type, stage) ; stage_id null pour les documents
 * permanents (fiche sanitaire, autorisation de rentrer seul). Les anciennes autorisations sans stage restent en base,
 * non affichées.
 */
final class Version20261102050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute youth_document.stage_id (autorisation de sortie du territoire par stage).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE youth_document ADD stage_id INT DEFAULT NULL');
        // Le nouvel index unique commence par owner_id : il sert la clé étrangère du propriétaire avant de retirer l'ancien.
        $this->addSql('CREATE UNIQUE INDEX uniq_youth_document_owner_type_stage ON youth_document (owner_id, type, stage_id)');
        $this->addSql('DROP INDEX uniq_youth_document_owner_type ON youth_document');
        $this->addSql('CREATE INDEX idx_youth_document_stage ON youth_document (stage_id)');
        $this->addSql('ALTER TABLE youth_document ADD CONSTRAINT fk_youth_document_stage FOREIGN KEY (stage_id) REFERENCES stage (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE youth_document DROP FOREIGN KEY fk_youth_document_stage');
        $this->addSql('DELETE FROM youth_document WHERE stage_id IS NOT NULL');
        $this->addSql('DROP INDEX idx_youth_document_stage ON youth_document');
        $this->addSql('CREATE UNIQUE INDEX uniq_youth_document_owner_type ON youth_document (owner_id, type)');
        $this->addSql('DROP INDEX uniq_youth_document_owner_type_stage ON youth_document');
        $this->addSql('ALTER TABLE youth_document DROP COLUMN stage_id');
    }
}
