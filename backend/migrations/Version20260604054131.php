<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase C : content_audience sur Article / Event / StaticPage.
 *
 * Tag transverse de catégorisation, orthogonal à `audience` (qui cible des
 * profils). Sert principalement à isoler les contenus « École de Triathlon »
 * pour les comptes Dirigeant — qui ne voient QUE les contenus tagués ainsi
 * (ou non tagués du tout, qui restent publics).
 *
 * Default '[]' : aucun contenu existant n'est tagué (= reste visible par tous).
 */
final class Version20260604054131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Article / Event / StaticPage : ajout content_audience (tag École Triathlon)';
    }

    public function up(Schema $schema): void
    {
        foreach (['article', 'event', 'static_page'] as $table) {
            $this->addSql("ALTER TABLE `{$table}` ADD content_audience JSON NOT NULL DEFAULT '[]'");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['article', 'event', 'static_page'] as $table) {
            $this->addSql("ALTER TABLE `{$table}` DROP content_audience");
        }
    }
}
