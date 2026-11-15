<?php

namespace App\Service\Csv;

class CsvImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $deactivated = 0;
    public int $skipped = 0;
    /**
     * Nombre d'adhérents absents du CSV qui auraient été désactivés
     * MAIS sont restés actifs grâce à la période de grâce.
     */
    public int $deactivationDeferred = 0;
    /** Date jusqu'à laquelle la grâce s'applique (pour le message). */
    public ?\DateTimeImmutable $gracePeriodUntil = null;
    /** Libellé de la saison associée à l'import (null si import sans saison). */
    public ?string $seasonLabel = null;
    /** true = simulation (rollback à la fin, aucun email dispatché). */
    public bool $dryRun = false;
    /**
     * true = import d'archives (stats uniquement) : nouveaux comptes
     * créés inactifs sans email, comptes existants non modifiés, aucun
     * effet de bord "saison courante" (désactivations, liaisons email,
     * parents externes).
     */
    public bool $historicalOnly = false;
    /** Parents externes désactivés (plus aucun enfant actif). */
    public int $externalParentsDeactivated = 0;
    /** Parents externes réactivés (au moins un enfant redevenu actif). */
    public int $externalParentsReactivated = 0;
    /**
     * Nombre d'emails de bienvenue effectivement dispatchés (nouveaux
     * comptes + adhérents renouvelant après une saison manquée).
     */
    public int $welcomeEmailsSent = 0;
    /**
     * Comptes temporaires (licence en attente) retrouvés dans le CSV et
     * complétés : « Prénom Nom (licence X) ».
     *
     * @var list<string>
     */
    public array $pendingMatched = [];
    /**
     * Lignes ignorées : deux licences la même saison pour un même adhérent (ex : compétition + loisir), seule la
     * licence compétition est importée (à défaut, la première du fichier) — « Prénom Nom (licence X, ligne N — ligne M
     * retenue : type) ». Comptées dans `skipped`.
     *
     * @var list<string>
     */
    public array $duplicates = [];
    /**
     * Adhérents NOUVEAUX POUR LA SAISON de l'import (première adhésion enregistrée pour cette saison : nouveaux
     * comptes et anciens adhérents qui reviennent), présentés à l'admin pour repérer les jeunes d'associations
     * sportives externes. `eligible` = cochable : mineur ET part club à 0 € (payé via HelloAsso, pas sur le site
     * de la FFTri). `alreadyTagged` = déjà marqué avant cet import ; `tagged` = marqué à l'issue de l'import.
     *
     * @var list<array{
     *     licence: string, name: string, email: string, birthDate: ?string, age: ?int, category: ?string,
     *     licenceType: ?string, partClubCents: ?int, totalCents: ?int, isNewAccount: bool, eligible: bool,
     *     reason: string, alreadyTagged: bool, tagged: bool
     * }>
     */
    public array $newForSeason = [];
    /**
     * Jeunes marqués « association sportive externe » par CET import (cases cochées par l'admin) :
     * « Prénom Nom (licence X) ». Leur compte est désactivé, sans e-mail de bienvenue.
     *
     * @var list<string>
     */
    public array $externalTagged = [];
    /**
     * Licences cochées mais refusées (pas mineur ou part club ≠ 0 €) : « Prénom Nom (licence X) — motif ».
     *
     * @var list<string>
     */
    public array $externalRefused = [];
    /** @var list<array{line: int, error: string, raw?: array<string, string>}> */
    public array $errors = [];

    public function addError(int $line, string $error, array $raw = []): void
    {
        $this->errors[] = ['line' => $line, 'error' => $error, 'raw' => $raw];
    }

    /**
     * Nouveaux pour la saison que l'admin peut cocher (mineurs, part club à 0 €), par ordre alphabétique.
     *
     * @return list<array<string, mixed>>
     */
    public function newForSeasonEligible(): array
    {
        return $this->sortedNewForSeason(true);
    }

    /**
     * Nouveaux pour la saison qui ne sont PAS cochables (majeurs ou part club ≠ 0 €), par ordre alphabétique.
     *
     * @return list<array<string, mixed>>
     */
    public function newForSeasonOthers(): array
    {
        return $this->sortedNewForSeason(false);
    }

    /** @return list<array<string, mixed>> */
    private function sortedNewForSeason(bool $eligible): array
    {
        $rows = array_values(array_filter($this->newForSeason, static fn (array $r) => $r['eligible'] === $eligible));
        usort($rows, static fn (array $a, array $b) => strcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8')));

        return $rows;
    }

    public function totalProcessed(): int
    {
        return $this->created + $this->updated + $this->skipped;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'deactivated' => $this->deactivated,
            'deactivationDeferred' => $this->deactivationDeferred,
            'gracePeriodUntil' => $this->gracePeriodUntil?->format('Y-m-d'),
            'seasonLabel' => $this->seasonLabel,
            'dryRun' => $this->dryRun,
            'historicalOnly' => $this->historicalOnly,
            'welcomeEmailsSent' => $this->welcomeEmailsSent,
            'pendingMatched' => $this->pendingMatched,
            'newForSeason' => count($this->newForSeason),
            'externalTagged' => $this->externalTagged,
            'externalParentsDeactivated' => $this->externalParentsDeactivated,
            'externalParentsReactivated' => $this->externalParentsReactivated,
            'skipped' => $this->skipped,
            'duplicates' => $this->duplicates,
            'errors' => $this->errors,
        ];
    }
}
