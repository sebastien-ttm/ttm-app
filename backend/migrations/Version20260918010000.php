<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute `article.icon` (VARCHAR(16) NULL) — un emoji court affiché
 * devant le titre dans le résumé mobile (carte article).
 *
 * Longueur 16 chars UTF-8mb4 pour absorber sans risque les emojis
 * composés (séquences ZWJ, drapeaux, modifiers de teint).
 */
final class Version20260918010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute article.icon (emoji affiché devant le titre).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD icon VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP icon');
    }
}
