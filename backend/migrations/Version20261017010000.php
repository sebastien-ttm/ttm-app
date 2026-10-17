<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bourse aux équipements : restaure la date de mise en ligne des annonces existantes.
 *
 * Version20261016010000 avait fixé `published_at` des annonces déjà en base à l'instant de la migration, ce qui les
 * montrait toutes « publiées » en même temps (liste et détail affichent cette date). On leur redonne leur vraie date
 * de création. Seules les annonces migrées sont concernées : elles partagent exactement le même horodatage (un
 * UPDATE, un NOW()), alors qu'une réactivation par son auteur ou une nouvelle annonce a le sien. Conséquence voulue :
 * une annonce de plus de 10 jours est désormais « expirée » (masquée de la liste) jusqu'à ce que son auteur la
 * réactive depuis « Mes annonces ».
 */
final class Version20261017010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_listing : redonne aux annonces migrées leur vraie date de mise en ligne (created_at).';
    }

    public function up(Schema $schema): void
    {
        // Table dérivée : MySQL interdit de lire la table mise à jour dans une sous-requête directe (erreur 1093).
        $this->addSql(<<<'SQL'
            UPDATE marketplace_listing
            SET published_at = created_at
            WHERE created_at < published_at
              AND published_at IN (
                  SELECT shared.published_at FROM (
                      SELECT published_at FROM marketplace_listing GROUP BY published_at HAVING COUNT(*) > 1
                  ) AS shared
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Les dates d'origine du déploiement ne sont pas reconstituables : sans effet.
    }
}
