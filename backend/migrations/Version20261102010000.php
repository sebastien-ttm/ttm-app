<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Intervalles d'allure par saison (perf_test_pace.season_start_year) et épreuve « 2000 m course à pied ».
 *
 * Les intervalles existants valaient pour toutes les saisons : ils sont recopiés pour la saison en cours et pour chaque
 * saison qui a des prises de temps (hors stages), puis les entraîneurs les ajustent saison par saison depuis le
 * backend. Le 2000 m démarre des intervalles du 1500 m × 4/3 (à ajuster).
 */
final class Version20261102010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'perf_test_pace : intervalles par saison ; ajoute run_2000.';
    }

    public function up(Schema $schema): void
    {
        $today = new \DateTimeImmutable('today');
        $current = (int) $today->format('n') >= 9 ? (int) $today->format('Y') : (int) $today->format('Y') - 1;

        $this->addSql('ALTER TABLE perf_test_pace ADD season_start_year SMALLINT DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_perf_test_pace_test ON perf_test_pace');
        // Les lignes existantes deviennent celles de la saison en cours…
        $this->addSql('UPDATE perf_test_pace SET season_start_year = '.$current);
        // … recopiées pour chaque saison précédente qui a des prises de temps (saison : 1er septembre → 31 août).
        $this->addSql('
            INSERT INTO perf_test_pace (test, season_start_year, level1_max, level2_max, level3_max, level4_max)
            SELECT p.test, s.y, p.level1_max, p.level2_max, p.level3_max, p.level4_max
            FROM perf_test_pace p
            CROSS JOIN (
                SELECT DISTINCT YEAR(test_date) - (MONTH(test_date) < 9) AS y
                FROM perf_test_session
                WHERE stage_id IS NULL
            ) s
            WHERE p.season_start_year = '.$current.' AND s.y <> '.$current.'
        ');
        // 2000 m course à pied : intervalles du 1500 m × 4/3, pour chaque saison où le 1500 m a les siens.
        $this->addSql("
            INSERT INTO perf_test_pace (test, season_start_year, level1_max, level2_max, level3_max, level4_max)
            SELECT 'run_2000', season_start_year, 399, 433, 460, 520
            FROM perf_test_pace
            WHERE test = 'run_1500'
        ");
        $this->addSql('ALTER TABLE perf_test_pace MODIFY season_start_year SMALLINT NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_perf_test_pace_test_season ON perf_test_pace (test, season_start_year)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_perf_test_pace_test_season ON perf_test_pace');
        // Une seule ligne par épreuve : celle de la saison la plus récente.
        $this->addSql('
            DELETE p FROM perf_test_pace p
            WHERE p.test = \'run_2000\'
               OR p.season_start_year < (SELECT m.y FROM (SELECT test, MAX(season_start_year) AS y FROM perf_test_pace GROUP BY test) m WHERE m.test = p.test)
        ');
        $this->addSql('ALTER TABLE perf_test_pace DROP season_start_year');
        $this->addSql('CREATE UNIQUE INDEX uniq_perf_test_pace_test ON perf_test_pace (test)');
    }
}
