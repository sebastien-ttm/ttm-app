<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSeason;
use App\Entity\UserSeasonMembership;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Csv\CsvImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques adhérents par saison, pour l'ensemble du club et séparément pour les jeunes (jusqu'à juniors) et les
 * seniors :
 *  - effectif total
 *  - nouvelles licences (adhérents sans aucune adhésion lors des saisons précédentes)
 *  - taux de réadhésion : part des adhérents de la saison précédente qui ont repris une licence (jeunes / seniors
 *    selon leur catégorie de la saison précédente : un junior devenu senior compte comme jeune réadhérent)
 *  - âge moyen calculé à la date de rattachement à la saison (date de début de saison, sinon date d'import)
 *  - pour l'ensemble : répartition par type de licence (snapshot au moment de l'import) et par sexe
 * et des courbes d'évolution au fil des saisons (adhérents, réadhésion, part des jeunes, âge moyen), dessinées en
 * SVG côté serveur.
 *
 * Utilise UserSeasonMembership : chaque saison compte donc les adhérents qui ONT effectivement une adhésion
 * enregistrée pour elle (backfill et imports historiques inclus).
 */
#[IsGranted('ROLE_ADMIN')]
class AdherentStatsController extends AbstractController
{
    public const GROUPS = ['all' => 'Ensemble', 'youth' => 'Jeunes', 'senior' => 'Seniors'];
    private const COLORS = ['all' => '#0d2148', 'youth' => '#16a34a', 'senior' => '#D32F2F'];

    /** Catégories d'âge FFTri « jeunes » (de mini-poussin à junior), comparées sans accents ni casse. */
    private const YOUTH_CATEGORIES = ['mini', 'poussin', 'pupille', 'benjamin', 'minime', 'cadet', 'junior'];
    /** Sans catégorie : jeune jusqu'à 19 ans dans l'année de fin de saison (âge FFTri des juniors). */
    private const YOUTH_MAX_AGE = 19;

    public function __construct(
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
    ) {
    }

    #[Route('/admin/adherents/stats', name: 'admin_adherents_stats')]
    public function index(): Response
    {
        /** @var list<TrainingSeason> $chrono saisons de la plus ancienne à la plus récente */
        $chrono = $this->seasons->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()->getResult();

        $bySeason = [];
        foreach ($this->memberships->findAllWithUserAndSeason() as $m) {
            $bySeason[(int) $m->getSeason()->getId()][] = $m;
        }

        $sections = [];
        $previous = null;        // adhérents de la saison précédente : id → groupe
        $everSeen = [];          // adhérents vus lors d'une saison précédente
        foreach ($chrono as $season) {
            $rows = $bySeason[(int) $season->getId()] ?? [];
            $groupOf = [];
            foreach ($rows as $m) {
                $groupOf[(int) $m->getUser()->getId()] = $this->groupOf($m);
            }
            $section = $this->summarize($season, $rows);
            $section['groups'] = $this->groupStats($season, $rows, $groupOf, $previous, $everSeen);
            $section['hasPrevious'] = $previous !== null;
            $sections[] = $section;

            if ($rows !== []) {
                $previous = $groupOf;
                $everSeen += $groupOf;
            }
        }

        // Courbes : uniquement les saisons qui ont des adhérents, dans l'ordre chronologique.
        $charted = array_values(array_filter($sections, static fn (array $s) => $s['total'] > 0));
        $labels = array_map(static fn (array $s) => self::shortLabel($s['season']), $charted);
        $series = static function (callable $value, array $keys) use ($charted): array {
            $out = [];
            foreach ($keys as $key) {
                $out[] = [
                    'name' => self::GROUPS[$key],
                    'color' => self::COLORS[$key],
                    'values' => array_map(static fn (array $s) => $value($s, $key), $charted),
                ];
            }
            return $out;
        };
        $keys = array_keys(self::GROUPS);
        $charts = $charted === [] ? [] : [
            $this->chart('Nombre d\'adhérents', $labels, $series(static fn ($s, $k) => $s['groups'][$k]['total'], $keys), '', true),
            $this->chart('Taux de réadhésion', $labels, $series(static fn ($s, $k) => $s['groups'][$k]['renewalRate'], $keys), ' %', true, 100.0),
            $this->chart('Part des jeunes', $labels, $series(
                static fn ($s, $k) => $s['total'] > 0 ? round($s['groups']['youth']['total'] / $s['total'] * 100, 1) : null,
                ['youth'],
            ), ' %', true, 100.0),
            $this->chart('Âge moyen', $labels, $series(static fn ($s, $k) => $s['groups'][$k]['avgAge'], $keys), ' ans', false),
        ];

        return $this->render('admin/adherents_stats.html.twig', [
            'sections' => array_reverse($sections),
            'charts' => $charts,
            'groupLabels' => self::GROUPS,
            'groupColors' => self::COLORS,
        ]);
    }

    /** Jeune (jusqu'à juniors) ou senior, d'après la catégorie d'âge de la saison, à défaut l'âge FFTri. */
    private function groupOf(UserSeasonMembership $m): string
    {
        $category = self::normalize((string) $m->getCategorieAge());
        if ($category !== '') {
            foreach (self::YOUTH_CATEGORIES as $youth) {
                if (str_starts_with($category, $youth)) {
                    return 'youth';
                }
            }
            return 'senior';
        }

        $birth = $m->getUser()->getDateNaissance();
        $ref = $m->getSeason()->getEndsAt() ?? $m->getSeason()->getStartsAt() ?? $m->getImportedAt();
        if ($birth !== null && (int) $ref->format('Y') - (int) $birth->format('Y') <= self::YOUTH_MAX_AGE) {
            return 'youth';
        }

        return 'senior';
    }

    /**
     * Effectif, nouvelles licences, réadhésion et âge moyen de la saison, pour l'ensemble, les jeunes et les seniors.
     *
     * @param list<UserSeasonMembership> $rows
     * @param array<int, string>         $groupOf  adhérents de la saison → groupe
     * @param array<int, string>|null    $previous adhérents de la saison précédente → groupe (null : pas de saison
     *                                             précédente avec des adhérents)
     * @param array<int, string>         $everSeen adhérents vus lors d'une saison précédente
     *
     * @return array<string, array{total: int, newLicences: ?int, renewed: ?int, previousTotal: ?int, renewalRate: ?float, avgAge: ?float}>
     */
    private function groupStats(TrainingSeason $season, array $rows, array $groupOf, ?array $previous, array $everSeen): array
    {
        $out = [];
        foreach (array_keys(self::GROUPS) as $key) {
            $in = static fn (string $group) => $key === 'all' || $group === $key;

            $ageSum = 0.0;
            $ageCount = 0;
            $total = 0;
            $new = 0;
            foreach ($rows as $m) {
                $id = (int) $m->getUser()->getId();
                if (!$in($groupOf[$id])) {
                    continue;
                }
                ++$total;
                if (!isset($everSeen[$id])) {
                    ++$new;
                }
                $birth = $m->getUser()->getDateNaissance();
                if ($birth !== null) {
                    $ageSum += ($season->getStartsAt() ?? $m->getImportedAt())->diff($birth)->y;
                    ++$ageCount;
                }
            }

            $previousTotal = null;
            $renewed = null;
            if ($previous !== null) {
                $previousTotal = 0;
                $renewed = 0;
                foreach ($previous as $id => $group) {
                    if (!$in($group)) {
                        continue;
                    }
                    ++$previousTotal;
                    if (isset($groupOf[$id])) {
                        ++$renewed;
                    }
                }
            }

            $out[$key] = [
                'total' => $total,
                // Sans saison précédente, tout le monde paraîtrait « nouveau » : non calculé.
                'newLicences' => $previous !== null ? $new : null,
                'renewed' => $renewed,
                'previousTotal' => $previousTotal,
                'renewalRate' => $previousTotal ? round($renewed / $previousTotal * 100, 1) : null,
                'avgAge' => $ageCount > 0 ? round($ageSum / $ageCount, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<UserSeasonMembership> $rows
     * @return array<string, mixed>
     */
    private function summarize(TrainingSeason $season, array $rows): array
    {
        $total = count($rows);
        $byType = ['Compétition' => 0, 'Loisir' => 0, 'Dirigeant' => 0, 'Non renseigné' => 0];
        $bySex = ['Hommes' => 0, 'Femmes' => 0, 'Non renseigné' => 0];

        foreach ($rows as $m) {
            $u = $m->getUser();
            // Type licence : snapshot pris à l'import (spécifique à la
            // saison — un adhérent peut avoir Loisir une saison et
            // Compétition la suivante). Re-normalisation défensive au
            // cas où le snapshot serait une valeur brute ancienne.
            $raw = $m->getTypeLicence();
            $type = $raw !== null ? (CsvImportService::normalizeTypeLicence($raw) ?? $raw) : null;
            $type = in_array($type, ['Compétition', 'Loisir', 'Dirigeant'], true) ? $type : 'Non renseigné';
            $byType[$type]++;

            // Sexe (sur User — pas snapshoté, prendre la valeur courante)
            $sex = $u->getSexe();
            if ($sex === 'm') { $bySex['Hommes']++; }
            elseif ($sex === 'f') { $bySex['Femmes']++; }
            else { $bySex['Non renseigné']++; }
        }

        return [
            'season' => $season,
            'total' => $total,
            'byType' => $byType,
            'bySex' => $bySex,
        ];
    }

    /**
     * Courbe SVG prête à dessiner : axes, graduations et une polyligne par série (les valeurs null interrompent la
     * ligne). `$fromZero` : l'axe vertical part de 0 ; `$max` : plafond imposé (100 % pour un taux).
     *
     * @param list<string>                                                      $labels
     * @param list<array{name: string, color: string, values: list<int|float|null>}> $series
     *
     * @return array<string, mixed>
     */
    private function chart(string $title, array $labels, array $series, string $unit, bool $fromZero, ?float $max = null): array
    {
        $width = 640;
        $height = 260;
        $left = 48;
        $right = 16;
        $top = 16;
        $bottom = 36;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $values = [];
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null) {
                    $values[] = (float) $v;
                }
            }
        }
        $lo = $values === [] ? 0.0 : min($values);
        $hi = $values === [] ? 1.0 : max($values);
        if ($fromZero) {
            $lo = 0.0;
        }
        if ($max !== null) {
            $hi = $max;
        }
        if ($hi - $lo < 1e-9) {
            $hi = $lo + 1;
        }
        // Graduations « rondes » : 5 intervalles environ.
        $raw = ($hi - $lo) / 5;
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude * (($raw / $magnitude) > 5 ? 10 : (($raw / $magnitude) > 2 ? 5 : (($raw / $magnitude) > 1 ? 2 : 1)));
        $lo = floor($lo / $step) * $step;
        $hi = ceil($hi / $step) * $step;

        $count = count($labels);
        $x = static fn (int $i): float => $left + ($count > 1 ? $i * $plotW / ($count - 1) : $plotW / 2);
        $y = static fn (float $v): float => $top + $plotH - ($v - $lo) / ($hi - $lo) * $plotH;

        $ticks = [];
        for ($v = $lo; $v <= $hi + $step / 2; $v += $step) {
            $ticks[] = ['y' => round($y($v), 1), 'label' => rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',')];
        }
        // Une étiquette d'abscisse sur deux au-delà de 10 saisons, pour rester lisible.
        $every = $count > 10 ? 2 : 1;
        $xLabels = [];
        foreach ($labels as $i => $label) {
            if ($i % $every === 0 || $i === $count - 1) {
                $xLabels[] = ['x' => round($x($i), 1), 'label' => $label];
            }
        }

        $lines = [];
        foreach ($series as $s) {
            $segments = [];
            $current = [];
            $dots = [];
            foreach ($s['values'] as $i => $v) {
                if ($v === null) {
                    if ($current !== []) { $segments[] = implode(' ', $current); }
                    $current = [];
                    continue;
                }
                $px = round($x($i), 1);
                $py = round($y((float) $v), 1);
                $current[] = $px.','.$py;
                $dots[] = ['x' => $px, 'y' => $py, 'title' => $labels[$i].' : '.number_format((float) $v, is_float($v) ? 1 : 0, ',', ' ').$unit];
            }
            if ($current !== []) { $segments[] = implode(' ', $current); }
            $lines[] = ['name' => $s['name'], 'color' => $s['color'], 'segments' => $segments, 'dots' => $dots];
        }

        return [
            'title' => $title,
            'width' => $width,
            'height' => $height,
            'left' => $left,
            'right' => $width - $right,
            'top' => $top,
            'bottom' => $top + $plotH,
            'ticks' => $ticks,
            'xLabels' => $xLabels,
            'lines' => $lines,
            'unit' => trim($unit),
        ];
    }

    /** « 2024-2025 » → « 24-25 » (abscisses des courbes). */
    private static function shortLabel(TrainingSeason $season): string
    {
        $label = (string) $season;

        return (string) preg_replace('/^\d{2}(\d{2})-\d{2}(\d{2})$/', '$1-$2', $label);
    }

    private static function normalize(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return strtolower(trim($ascii === false ? $text : $ascii));
    }
}
