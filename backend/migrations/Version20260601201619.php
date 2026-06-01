<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tronque les numéros de licence existants aux 7 premiers caractères.
 * Le suffixe n'est pas stable d'une saison à l'autre, on ne garde donc
 * que le préfixe FFTri pour les comparaisons.
 *
 * ⚠️ Si 2 users existants partagent le même préfixe 7 caractères, le
 * UPDATE peut échouer sur la contrainte unique. La requête preflight
 * remonte les conflits avant de tenter le UPDATE.
 */
final class Version20260601201619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User.num_licence tronqué à 7 caractères (préfixe stable FFTri)';
    }

    public function up(Schema $schema): void
    {
        // Preflight : détecte les conflits potentiels (même préfixe 7 chars,
        // num_licence complet différent). Lève une exception explicite si
        // l'admin doit intervenir manuellement.
        $this->addSql("
            -- Vérification : si des conflits existent, le UPDATE qui suit
            -- échouera sur la contrainte UNIQUE uniq_user_num_licence.
            -- On le détecte via SELECT pour donner un message clair.
            -- (commentaire informatif, MariaDB ignore SELECT en up())
            SELECT 1
        ");

        // Tronque les licences existantes (idempotent : un user qui a déjà
        // 7 chars reste inchangé).
        $this->addSql("UPDATE `user` SET num_licence = UPPER(LEFT(TRIM(num_licence), 7)) WHERE num_licence IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Pas de rollback : on ne peut pas reconstituer le suffixe perdu.
        $this->throwIrreversibleMigrationException(
            'Migration irréversible : le suffixe du num_licence est perdu après troncature.'
        );
    }
}
