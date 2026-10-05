<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Créateur d'un événement et d'une page statique, pour que les éditeurs
 * ne puissent supprimer que leurs propres contenus (ContentDeleteVoter).
 * Les lignes existantes restent à NULL : seuls un entraîneur ou un admin
 * peuvent les supprimer.
 */
final class Version20261005010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute created_by_id sur event et static_page.';
    }

    public function up(Schema $schema): void
    {
        foreach (['event' => 'EVENT', 'static_page' => 'STATIC_PAGE'] as $table => $key) {
            $this->addSql(sprintf('ALTER TABLE %s ADD created_by_id INT DEFAULT NULL', $table));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT FK_%s_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL',
                $table,
                $key,
            ));
            $this->addSql(sprintf('CREATE INDEX idx_%s_created_by ON %s (created_by_id)', $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['event' => 'EVENT', 'static_page' => 'STATIC_PAGE'] as $table => $key) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY FK_%s_CREATED_BY', $table, $key));
            $this->addSql(sprintf('DROP INDEX idx_%s_created_by ON %s', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP created_by_id', $table));
        }
    }
}
