<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compétitions proposées : modération par un administrateur avant publication (statut en attente / validée / en pause /
 * refusée). Additive : les compétitions déjà proposées restent publiées (statut « validée », date de publication =
 * date de création, rang de publication = identifiant, ce qui laisse inchangé le repère « nouveautés non vues »).
 *
 * À exécuter AVANT de déployer le nouveau code (qui lit ces colonnes).
 */
final class Version20261116010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'race_proposal : status (modération), moderation_note, published_at, published_rank ; les propositions existantes restent publiées.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE race_proposal
                ADD status VARCHAR(20) DEFAULT \'validated\' NOT NULL,
                ADD moderation_note VARCHAR(500) DEFAULT NULL,
                ADD published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                ADD published_rank INT DEFAULT NULL
        ');
        $this->addSql('UPDATE race_proposal SET published_at = created_at, published_rank = id');
        $this->addSql('CREATE INDEX idx_race_proposal_status ON race_proposal (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_race_proposal_status ON race_proposal');
        $this->addSql('ALTER TABLE race_proposal DROP status, DROP moderation_note, DROP published_at, DROP published_rank');
    }
}
