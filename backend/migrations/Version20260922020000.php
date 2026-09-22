<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étend le modèle Comment :
 *  - article_id devient nullable (les commentaires peuvent maintenant
 *    porter sur un événement à la place).
 *  - Nouvelle colonne event_id (FK vers event, ON DELETE CASCADE).
 *  - Nouvelle colonne parent_id (self-ref, ON DELETE CASCADE) pour le
 *    threading : les admins peuvent répondre à n'importe quel
 *    commentaire, plusieurs fois, sans limite de profondeur.
 *
 * Aucune donnée existante n'est migrée : les commentaires d'articles
 * conservent leur article_id et parent_id/event_id restent NULL.
 */
final class Version20260922020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comment : article nullable + event + parent (threading).';
    }

    public function up(Schema $schema): void
    {
        // 1) Autorise article_id à être NULL (pour les commentaires
        //    rattachés à un événement).
        $this->addSql('ALTER TABLE comment MODIFY article_id INT DEFAULT NULL');

        // 2) Ajoute event_id + parent_id + leurs index et FKs.
        $this->addSql('ALTER TABLE comment
            ADD event_id INT DEFAULT NULL,
            ADD parent_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_comment_event ON comment (event_id)');
        $this->addSql('CREATE INDEX idx_comment_parent ON comment (parent_id)');
        $this->addSql('ALTER TABLE comment
            ADD CONSTRAINT FK_COMMENT_EVENT FOREIGN KEY (event_id)
                REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment
            ADD CONSTRAINT FK_COMMENT_PARENT FOREIGN KEY (parent_id)
                REFERENCES comment (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_PARENT');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_EVENT');
        $this->addSql('DROP INDEX idx_comment_parent ON comment');
        $this->addSql('DROP INDEX idx_comment_event ON comment');
        $this->addSql('ALTER TABLE comment DROP event_id, DROP parent_id');
        // Restaure NOT NULL sur article_id — attention : si des lignes
        // event_only existent, la migration down échouera. C'est
        // intentionnel : la mise en place du modèle threaded est
        // conçue comme un one-way par défaut.
        $this->addSql('ALTER TABLE comment MODIFY article_id INT NOT NULL');
    }
}
