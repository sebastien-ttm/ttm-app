<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comité directeur :
 *  - commentaires modifiables par leur auteur : `updated_at` (date de la dernière modification, NULL = jamais modifié) ;
 *  - « Qui s'exprime ? » en nom complet : les points existants dont l'intervenant était le simple prénom de leur
 *    auteur (valeur par défaut jusqu'ici) passent au nom complet de cet auteur. Les saisies libres différentes
 *    (ex. un prénom tapé par le secrétaire) ne sont pas touchées : elles se corrigent depuis « Modifier le point ».
 *
 * Additive côté schéma. À exécuter AVANT de déployer le nouveau code (qui lit `updated_at`).
 */
final class Version20261118010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'board_item_comment : updated_at (commentaire modifié) ; board_agenda_item.speaker : prénom de l\'auteur -> nom complet.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_item_comment ADD updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('
            UPDATE board_agenda_item i
            INNER JOIN `user` u ON u.id = i.author_id
            SET i.speaker = LEFT(TRIM(CONCAT(u.prenom, \' \', u.nom)), 120)
            WHERE i.speaker = u.prenom
        ');
    }

    public function down(Schema $schema): void
    {
        // Le nom complet des intervenants n'est pas rétabli en simple prénom (donnée enrichie, pas perdue).
        $this->addSql('ALTER TABLE board_item_comment DROP updated_at');
    }
}
