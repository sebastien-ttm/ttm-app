<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Échelle des 5 allures du club par épreuve (perf_test_pace) : temps max inclus, en secondes, des allures 1 à 4 ;
 * l'allure 5 n'a pas de borne. Valeurs initiales : fichier « Allures - Intervalles » du club, ensuite modifiables par
 * les entraîneurs depuis le backend.
 */
final class Version20261025010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'perf_test_pace : intervalles des 5 allures par épreuve (natation, vélo, course).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE perf_test_pace (
                id INT AUTO_INCREMENT NOT NULL,
                test VARCHAR(32) NOT NULL,
                level1_max SMALLINT NOT NULL,
                level2_max SMALLINT NOT NULL,
                level3_max SMALLINT NOT NULL,
                level4_max SMALLINT NOT NULL,
                UNIQUE INDEX uniq_perf_test_pace_test (test),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        // 400 m natation : < 6\'00 · 6\'00-6\'29 · 6\'30-6\'59 · 7\'00-7\'59 · 8\'00 et plus
        $this->addSql("INSERT INTO perf_test_pace (test, level1_max, level2_max, level3_max, level4_max) VALUES ('swim_400', 359, 389, 419, 479)");
        // Montée 2 km vélo : < 5'35 · 5'35-6'04 · 6'05-6'42 · 6'43-7'29 · 7'30 et plus
        $this->addSql("INSERT INTO perf_test_pace (test, level1_max, level2_max, level3_max, level4_max) VALUES ('bike_climb_2k', 334, 364, 402, 449)");
        // 1500 m course : < 5'00 · 5'00-5'25 · 5'26-5'45 · 5'46-6'30 · 6'31 et plus
        $this->addSql("INSERT INTO perf_test_pace (test, level1_max, level2_max, level3_max, level4_max) VALUES ('run_1500', 299, 325, 345, 390)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE perf_test_pace');
    }
}
