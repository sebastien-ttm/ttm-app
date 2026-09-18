<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étend article.icon de 16 → 32 caractères. Certains emojis composés
 * (drapeaux régionaux, familles ZWJ, sous-drapeaux) peuvent dépasser
 * 16 codepoints — la longueur précédente aurait tronqué silencieusement
 * la séquence, cassant l'emoji.
 */
final class Version20260918020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étend article.icon 16 → 32 caractères (emojis composés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article MODIFY icon VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article MODIFY icon VARCHAR(16) DEFAULT NULL');
    }
}
