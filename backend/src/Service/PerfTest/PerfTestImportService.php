<?php

namespace App\Service\PerfTest;

use App\Entity\PerfTestResult;
use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Enum\PerfTest;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\String\u;

/**
 * Import de temps collés depuis Excel (une ligne par adhérent) dans une
 * séance de test : lecture des lignes, rapprochement des noms avec les
 * adhérents, puis enregistrement.
 *
 * Format accepté d'une ligne : « Nom<TAB>Prénom<TAB>Temps » (collage Excel),
 * « Nom Prénom<TAB>Temps », ou « DUPONT Jean 5:42 » ; une colonne de rang en
 * tête est ignorée. Le temps est TOUJOURS la dernière colonne.
 *
 * Statuts d'une ligne analysée :
 *  - ok         : un seul adhérent correspond exactement ;
 *  - ambiguous  : plusieurs adhérents portent ce nom (à choisir) ;
 *  - fuzzy      : nom approchant (faute de frappe, nom composé…) à confirmer ;
 *  - unknown    : aucun adhérent trouvé ;
 *  - invalid    : nom manquant, temps illisible ou invraisemblable ;
 *  - duplicate  : même adhérent déjà présent plus haut dans la liste.
 */
class PerfTestImportService
{
    private const MAX_FUZZY_CANDIDATES = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<User>                  $users    adhérents candidats
     * @param array<int, PerfTestResult>  $existing temps déjà saisis sur la séance, par id d'adhérent
     *
     * @return list<array{line: int, raw: string, name: string, seconds: ?int, time: ?string, status: string, message: ?string, candidates: list<User>, existing: ?string}>
     */
    public function analyse(PerfTestSession $session, string $text, array $users, array $existing): array
    {
        $index = $this->buildIndex($users);
        $rows = [];
        $seen = [];

        foreach ($this->parseLines($text, $session->getTest()) as $parsed) {
            $row = $parsed + ['status' => 'unknown', 'message' => null, 'candidates' => [], 'existing' => null];

            if ($parsed['error'] !== null) {
                $row['status'] = 'invalid';
                $row['message'] = $parsed['error'];
                $rows[] = $row;
                continue;
            }

            [$status, $candidates] = $this->match($parsed['name'], $index);
            $row['status'] = $status;
            $row['candidates'] = $candidates;

            if ($status === 'ok') {
                $user = $candidates[0];
                if (isset($seen[$user->getId()])) {
                    $row['status'] = 'duplicate';
                    $row['message'] = 'Déjà présent plus haut dans la liste : ligne ignorée.';
                } else {
                    $seen[$user->getId()] = true;
                    $current = $existing[$user->getId()] ?? null;
                    $row['existing'] = $current !== null ? PerfTestResult::format($current->getTimeSeconds()) : null;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Enregistre les lignes reconnues, plus les lignes à confirmer ou non
     * reconnues pour lesquelles un choix a été fait à la main
     * (`$choices` : n° de ligne => id d'adhérent, ou « legacy » pour garder
     * le nom d'un ancien adhérent sans compte).
     *
     * @param list<User>                 $users
     * @param array<int, PerfTestResult> $existing       temps déjà saisis, par id d'adhérent
     * @param list<PerfTestResult>       $existingLegacy temps déjà saisis d'anciens adhérents (sans compte)
     * @param array<int|string, mixed>   $choices
     *
     * @return array{created: int, updated: int, unchanged: int, skipped: int, legacy: int}
     */
    public function commit(PerfTestSession $session, string $text, array $users, array $existing, array $existingLegacy, array $choices, ?User $by): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'legacy' => 0];
        $done = [];
        $doneLegacy = [];
        $byId = [];
        foreach ($users as $candidateUser) {
            $byId[$candidateUser->getId()] = $candidateUser;
        }
        $legacyByKey = [];
        foreach ($existingLegacy as $legacyResult) {
            $legacyByKey[implode(' ', $this->tokens((string) $legacyResult->getLegacyName()))] = $legacyResult;
        }

        foreach ($this->analyse($session, $text, $users, $existing) as $row) {
            if ($row['seconds'] === null) {
                $summary['skipped']++;
                continue;
            }

            $user = null;
            $choice = $row['status'] === 'ok' ? '' : (string) ($choices[$row['line']] ?? '');

            if ($row['status'] === 'ok') {
                $user = $row['candidates'][0];
            } elseif ($row['status'] === 'ambiguous') {
                // Homonymes : le choix se fait parmi eux uniquement.
                foreach ($row['candidates'] as $candidate) {
                    if ($candidate->getId() === (int) $choice) {
                        $user = $candidate;
                    }
                }
            } elseif ($row['status'] === 'fuzzy' || $row['status'] === 'unknown') {
                if ($choice === 'legacy') {
                    // Ancien adhérent : on garde le nom tel qu'écrit dans la feuille, sans compte.
                    if ($this->commitLegacy($session, $row, $legacyByKey, $doneLegacy, $by, $summary)) {
                        $summary['legacy']++;
                    } else {
                        $summary['skipped']++;
                    }
                    continue;
                }
                // Nom approchant ou non reconnu : n'importe quel adhérent actif.
                $user = $byId[(int) $choice] ?? null;
            }

            if ($user === null || isset($done[$user->getId()])) {
                $summary['skipped']++;
                continue;
            }
            $done[$user->getId()] = true;

            $current = $existing[$user->getId()] ?? null;
            if ($current === null) {
                $this->em->persist(new PerfTestResult($session, $user, $row['seconds'], $by));
                $summary['created']++;
            } elseif ($current->getTimeSeconds() !== $row['seconds']) {
                $current->setTime($row['seconds'], $by);
                $summary['updated']++;
            } else {
                $summary['unchanged']++;
            }
        }
        $this->em->flush();

        return $summary;
    }

    /**
     * Enregistre (ou met à jour, ou laisse tel quel) le temps d'un ancien
     * adhérent identifié par son nom. Un même nom n'est pris qu'une fois par import.
     *
     * @param array{line: int, name: string, seconds: ?int}         $row
     * @param array<string, PerfTestResult>                          $legacyByKey
     * @param array<string, true>                                    $doneLegacy
     * @param array{created: int, updated: int, unchanged: int, skipped: int, legacy: int} $summary
     *
     * @return bool false si la ligne est ignorée (nom vide ou doublon dans la liste)
     */
    private function commitLegacy(PerfTestSession $session, array $row, array $legacyByKey, array &$doneLegacy, ?User $by, array &$summary): bool
    {
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $row['name'])), 0, 160);
        $key = implode(' ', $this->tokens($name));
        if ($name === '' || $key === '' || isset($doneLegacy[$key]) || $row['seconds'] === null) {
            return false;
        }
        $doneLegacy[$key] = true;

        $current = $legacyByKey[$key] ?? null;
        if ($current === null) {
            $this->em->persist(new PerfTestResult($session, null, $row['seconds'], $by, $name));
            $summary['created']++;
        } elseif ($current->getTimeSeconds() !== $row['seconds']) {
            $current->setTime($row['seconds'], $by);
            $summary['updated']++;
        } else {
            $summary['unchanged']++;
        }
        return true;
    }

    /**
     * @return list<array{line: int, raw: string, name: string, seconds: ?int, time: ?string, error: ?string}>
     */
    private function parseLines(string $text, PerfTest $test): array
    {
        [$min, $max] = $test->plausibleSeconds();
        $out = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $i => $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }

            if (str_contains($line, "\t")) {
                $cells = explode("\t", $line);
            } elseif (str_contains($line, ';')) {
                $cells = explode(';', $line);
            } else {
                $cells = [$line];
            }
            $cells = array_values(array_filter(array_map('trim', $cells), static fn (string $c) => $c !== ''));

            if (count($cells) === 1) {
                // Ni tabulation ni « ; » : « DUPONT Jean 5:42 » → dernier mot = temps.
                $words = preg_split('/\s+/', $cells[0], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $cells = count($words) >= 2
                    ? [implode(' ', array_slice($words, 0, -1)), $words[count($words) - 1]]
                    : $words;
            }
            // Colonne « rang » éventuelle en tête (« 1  DUPONT  Jean  5:42 »).
            if (count($cells) >= 3 && preg_match('/^\d+$/', $cells[0])) {
                array_shift($cells);
            }

            $timeCell = (string) array_pop($cells);
            $name = implode(' ', $cells);
            $seconds = $this->parseTime($timeCell);

            // Ligne d'en-tête (« Nom  Prénom  Temps ») : sans chiffre, avec un mot de titre de colonne.
            if ($seconds === null && !preg_match('/\d/', $line)
                && preg_match('/\b(nom|pr[ée]nom|temps|chrono|classement|rang|licen[cs]e|cat[ée]gorie)\b/iu', $line)) {
                continue;
            }

            $error = null;
            if ($name === '') {
                $error = 'Nom manquant.';
            } elseif ($seconds === null) {
                $error = sprintf('Temps illisible : « %s » (attendu : 5:42 ou 1:02:15).', $timeCell);
            } elseif ($seconds < $min || $seconds > $max) {
                $error = sprintf(
                    'Temps invraisemblable (%s) : vérifiez le format de la colonne (mm:ss, pas hh:mm).',
                    PerfTestResult::format($seconds),
                );
            }

            $out[] = [
                'line' => $i + 1,
                'raw' => $line,
                'name' => $name,
                'seconds' => $error === null ? $seconds : null,
                'time' => $seconds !== null ? PerfTestResult::format($seconds) : null,
                'error' => $error,
            ];
        }

        return $out;
    }

    /**
     * Temps d'une cellule Excel. Accepte aussi les fractions de seconde
     * (« 0:05:42,6 » → arrondi à la seconde).
     */
    private function parseTime(string $cell): ?int
    {
        $cell = trim($cell);
        if (preg_match('/^(\d+:\d{1,2}(?::\d{1,2})?)[.,](\d+)$/', $cell, $m)) {
            $base = PerfTestResult::parse($m[1]);
            return $base === null ? null : $base + ((float) ('0.'.$m[2]) >= 0.5 ? 1 : 0);
        }
        return PerfTestResult::parse($cell);
    }

    /**
     * @param list<User> $users
     *
     * @return array{exact: array<string, list<User>>, all: list<array{user: User, tokens: list<string>, key: string}>}
     */
    private function buildIndex(array $users): array
    {
        $exact = [];
        $all = [];
        foreach ($users as $user) {
            $tokens = $this->tokens($user->getNom().' '.$user->getPrenom());
            $key = implode(' ', $tokens);
            $exact[$key][] = $user;
            $all[] = ['user' => $user, 'tokens' => $tokens, 'key' => $key];
        }
        return ['exact' => $exact, 'all' => $all];
    }

    /**
     * Mots d'un nom, sans accents ni casse ni ponctuation, triés : l'ordre
     * « Nom Prénom » / « Prénom Nom » et les tirets n'ont donc pas d'importance.
     *
     * @return list<string>
     */
    private function tokens(string $name): array
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', (string) u($name)->ascii()->lower()));
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);
        sort($tokens);
        return $tokens;
    }

    /**
     * @param array{exact: array<string, list<User>>, all: list<array{user: User, tokens: list<string>, key: string}>} $index
     *
     * @return array{string, list<User>}
     */
    private function match(string $name, array $index): array
    {
        $tokens = $this->tokens($name);
        if ($tokens === []) {
            return ['unknown', []];
        }
        $key = implode(' ', $tokens);

        if (isset($index['exact'][$key])) {
            $matches = $index['exact'][$key];
            return [count($matches) === 1 ? 'ok' : 'ambiguous', $matches];
        }

        $found = [];
        foreach ($index['all'] as $entry) {
            // Nom partiel ou composé : les mots de l'un sont tous dans l'autre.
            $subset = count($tokens) >= 2 && count($entry['tokens']) >= 2
                && (array_diff($tokens, $entry['tokens']) === [] || array_diff($entry['tokens'], $tokens) === []);
            // Faute de frappe : 2 caractères d'écart au plus.
            $typo = strlen($key) >= 6 && strlen($key) <= 200 && levenshtein($key, $entry['key']) <= 2;
            if ($subset || $typo) {
                $found[] = $entry['user'];
            }
        }
        if ($found === []) {
            return ['unknown', []];
        }
        return ['fuzzy', array_slice($found, 0, self::MAX_FUZZY_CANDIDATES)];
    }
}
