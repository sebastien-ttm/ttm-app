<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Articles : intégration d'événements du calendrier existants
 * (rendus comme la section « Prochainement » côté mobile).
 */
final class Version20260927010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée article_event (liaison ManyToMany article ↔ événements intégrés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_event (
            article_id INT NOT NULL,
            event_id INT NOT NULL,
            INDEX IDX_4C1978B67294869C (article_id),
            INDEX IDX_4C1978B671F7E88B (event_id),
            PRIMARY KEY(article_id, event_id),
            CONSTRAINT FK_4C1978B67294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE,
            CONSTRAINT FK_4C1978B671F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_event');
    }
}
