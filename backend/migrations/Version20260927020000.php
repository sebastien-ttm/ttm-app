<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Onglet Social : courses proposées par les adhérents (capitaine ou
 * non) + votes d'intérêt des autres adhérents.
 */
final class Version20260927020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée race_proposal + race_proposal_vote (courses proposées et votes d\'intérêt).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE race_proposal (
            id INT AUTO_INCREMENT NOT NULL,
            author_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            race_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            url VARCHAR(500) DEFAULT NULL,
            captain TINYINT(1) NOT NULL,
            type VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_race_proposal_date (race_date),
            INDEX IDX_RACE_PROPOSAL_AUTHOR (author_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_RACE_PROPOSAL_AUTHOR FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE race_proposal_vote (
            id INT AUTO_INCREMENT NOT NULL,
            proposal_id INT NOT NULL,
            user_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_race_proposal_vote_user (proposal_id, user_id),
            INDEX IDX_RACE_PROPOSAL_VOTE_USER (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_RACE_PROPOSAL_VOTE_PROPOSAL FOREIGN KEY (proposal_id) REFERENCES race_proposal (id) ON DELETE CASCADE,
            CONSTRAINT FK_RACE_PROPOSAL_VOTE_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE race_proposal_vote');
        $this->addSql('DROP TABLE race_proposal');
    }
}
