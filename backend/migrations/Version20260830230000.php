<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire complètement U25/Performance de l'enum Profile :
 *  - Nettoie user.profiles : supprime les entrées "u25" et "performance"
 *    (handled by string REPLACE sur le JSON stocké) — quel que soit
 *    l'état d'avancement de la migration précédente Version20260830220000.
 *  - Ramène membership_fee.profile 'performance' → 'u25' (label tarif).
 *  - Ajoute user_season_membership.tariff_profile : override manuel du
 *    profil tarifaire à la génération de facture (NULL = auto Jeune/Sénior).
 */
final class Version20260830230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop U25/Performance profile, restore U25 as manual tariff selector on membership.';
    }

    public function up(Schema $schema): void
    {
        // Nettoie user.profiles (stocké en JSON string) — 3 formes possibles
        // dans le tableau : [",X"], ["X,"], ["X"] (seul élément).
        foreach (['u25', 'performance'] as $val) {
            $q = '"'.$val.'"';
            $this->addSql("UPDATE user SET profiles = REPLACE(profiles, ',$q', '') WHERE profiles LIKE '%,$q%'");
            $this->addSql("UPDATE user SET profiles = REPLACE(profiles, '$q,', '') WHERE profiles LIKE '%$q,%'");
            $this->addSql("UPDATE user SET profiles = '[]' WHERE profiles = '[$q]'");
        }

        // Rollback rename précédent (idempotent — no-op si pas appliqué).
        $this->addSql("UPDATE membership_fee SET profile = 'u25' WHERE profile = 'performance'");

        // Nouvelle colonne pour override tarif à la facture.
        $this->addSql('ALTER TABLE user_season_membership ADD tariff_profile VARCHAR(24) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership DROP tariff_profile');
        // Pas de restore automatique des entrées u25/performance dans
        // user.profiles — c'était une donnée métier, à ressaisir à la main
        // si besoin.
    }
}
