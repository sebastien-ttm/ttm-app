<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

/**
 * Tronque les num_licence existants aux 6 premiers caractères
 * (auparavant 7 — le 7e étant le type de licence FFTri qui peut
 * évoluer d'une saison à l'autre pour un même adhérent).
 *
 * Défense contre les doublons : si tronquer à 6 chars produirait
 * une collision sur la contrainte d'unicité `uniq_user_num_licence`,
 * la migration s'arrête AVANT tout UPDATE et liste les doublons à
 * résoudre manuellement (deux users qui partageaient les 6 premiers
 * caractères = très probablement le même adhérent référencé sous
 * plusieurs types de licence dans des saisons différentes ; à
 * fusionner via l'admin avant de relancer).
 */
final class Version20260911010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tronque user.num_licence aux 6 premiers caractères (LICENCE_PREFIX_LENGTH: 7 → 6).';
    }

    public function up(Schema $schema): void
    {
        // Détection préalable des doublons potentiels.
        $conflicts = $this->connection->fetchAllAssociative(
            "SELECT LEFT(num_licence, 6) AS prefix6, "
            ."GROUP_CONCAT(CONCAT(id, ':', num_licence) SEPARATOR ' | ') AS rows_ "
            ."FROM `user` "
            ."WHERE num_licence IS NOT NULL "
            ."GROUP BY LEFT(num_licence, 6) "
            ."HAVING COUNT(*) > 1"
        );
        if (count($conflicts) > 0) {
            $lines = ["Migration bloquée : plusieurs users partagent les 6 premiers caractères de leur licence."];
            $lines[] = 'Résolvez ces doublons manuellement (fusion / désactivation) puis relancez :';
            foreach ($conflicts as $c) {
                $lines[] = sprintf('  %s → %s', $c['prefix6'], $c['rows_']);
            }
            throw new AbortMigration(implode("\n", $lines));
        }

        // Aucun doublon → troncature safe.
        $this->addSql('UPDATE `user` SET num_licence = LEFT(num_licence, 6) WHERE num_licence IS NOT NULL AND CHAR_LENGTH(num_licence) > 6');
    }

    public function down(Schema $schema): void
    {
        // La donnée d'origine (7e caractère) est irrécupérable après
        // troncature — pas de down possible.
        $this->throwIrreversibleMigrationException(
            'Cette migration est irréversible : le 7e caractère (type de licence) a été perdu.'
        );
    }
}
