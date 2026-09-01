<?php

namespace App\Service\Csv;

use App\Entity\TrainingSeason;
use App\Entity\User;
use App\Entity\UserSeasonMembership;
use App\Enum\Profile;
use App\Enum\UserType;
use App\Message\SendMagicLinkEmailMessage;
use App\Repository\MembershipSettingsRepository;
use App\Repository\UserRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\MagicLinkService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use League\Csv\Statement;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Import des adhérents depuis un export "Excel" de l'Espace Tri (FFTri).
 */
class CsvImportService
{
    private const COL_NUM_LICENCE = 'Numéro de licence';
    private const COL_NOM = 'Nom';
    /**
     * Nom d'usage FFTri (nom marital, pseudonyme, etc.). Optionnel dans le CSV.
     * Quand renseigné, remplace le "Nom" (nom de naissance) à l'import.
     */
    private const COL_NOM_USAGE = 'Nom d\'usage';
    private const COL_PRENOM = 'Prénom';
    private const COL_DATE_NAISSANCE = 'Date de naissance';
    private const COL_SEXE = 'Civilité/genre';
    private const COL_ADRESSE = 'Adresse';
    private const COL_ADRESSE_PRINCIPALE = 'Adresse principale';
    private const COL_ADRESSE_DETAILS = 'Adresse Détails';
    private const COL_LIEU_DIT = 'Lieu-dit ou boîte postale';
    private const COL_CODE_POSTAL = 'Code Postal';
    private const COL_VILLE = 'Ville';
    private const COL_PAYS = 'Pays';
    private const COL_EMAIL = 'Email';
    private const COL_MOBILE = 'Mobile';
    private const COL_TELEPHONE = 'Téléphone';
    private const COL_STATUT = 'Statut';
    private const COL_TYPE_LICENCE = 'Type de licence';
    private const COL_CATEGORIE_AGE = 'Catégorie d\'âge';

    /**
     * Alias historiques pour chaque colonne canonique — permet d'accepter
     * les anciens exports FFTri (« N° Licence », « Sexe »…) en plus des
     * nouveaux (« Numéro de licence », « Civilité/genre »…). L'ordre
     * représente la priorité de résolution : la première clé trouvée dans
     * la ligne CSV gagne.
     */
    private const COL_ALIASES = [
        self::COL_NUM_LICENCE => ['Numéro de licence', 'N° Licence', 'Numero de licence'],
        self::COL_SEXE => ['Civilité/genre', 'Civilite/genre', 'Sexe'],
        self::COL_TELEPHONE => ['Téléphone', 'Telephone'],
        self::COL_MOBILE => ['Mobile'],
    ];

    /**
     * Colonnes obligatoires : au moins un alias de chacune doit être
     * présent dans l'en-tête, sinon on refuse l'import.
     * `Statut` a disparu du nouveau format FFTri → tous les adhérents
     * importés sont considérés actifs (Créé(e) = date d'inscription).
     */
    private const REQUIRED_COLUMNS = [
        self::COL_NUM_LICENCE,
        self::COL_NOM,
        self::COL_PRENOM,
        self::COL_EMAIL,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
        private readonly MagicLinkService $magicLinks,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $csvImportLogger,
        private readonly MembershipSettingsRepository $membership,
        private readonly UserSeasonMembershipRepository $memberships,
    ) {
    }

    public function import(
        string $filePath,
        bool $sendWelcomeEmails = true,
        string $delimiter = ',',
        ?TrainingSeason $season = null,
        bool $dryRun = false,
    ): CsvImportResult {
        $result = new CsvImportResult();
        $result->seasonLabel = $season?->__toString();
        $result->dryRun = $dryRun;
        $importedAt = new \DateTimeImmutable();

        // Transaction : en dry-run on rollback tout à la fin ; en réel on
        // commit. Isole aussi les erreurs partielles (si un flush plante
        // à mi-parcours, aucun user à moitié importé ne reste en base).
        $this->em->beginTransaction();

        // Auto-détection du délimiteur : le CSV FFTri est parfois exporté
        // en TSV (tab) ou séparé par point-virgule. On teste sur la
        // première ligne et on garde le séparateur qui produit le plus
        // de colonnes (heuristique : > 2). Fallback = valeur passée.
        $delimiter = $this->detectDelimiter($filePath, $delimiter);

        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);
        $csv->skipInputBOM();

        // Détection encodage : les exports Excel FFTri sont souvent en
        // Windows-1252 (accents 1 byte : é = 0xE9). Sans conversion, les
        // en-têtes lus contiennent des séquences invalides UTF-8 et
        // « Num\xE9ro » ne matche pas la constante PHP « Numéro » (UTF-8
        // 2 bytes : é = 0xC3 0xA9). On teste la validité UTF-8 du fichier
        // — si non valide → stream filter Windows-1252 → UTF-8.
        if (!$this->isValidUtf8($filePath)) {
            CharsetConverter::addTo($csv, 'Windows-1252', 'UTF-8');
        }

        $headers = $csv->getHeader();
        // Map normalized-header → original-header. Permet une comparaison
        // tolérante (casse, accents, espaces multiples) tout en préservant
        // les clés brutes du record pour resolveCol().
        $normalizedHeaders = [];
        foreach ($headers as $h) {
            $normalizedHeaders[self::normalizeHeader($h)] = $h;
        }

        foreach (self::REQUIRED_COLUMNS as $required) {
            $candidates = self::COL_ALIASES[$required] ?? [$required];
            $found = false;
            foreach ($candidates as $c) {
                if (isset($normalizedHeaders[self::normalizeHeader($c)])) { $found = true; break; }
            }
            if (!$found) {
                $result->addError(0, sprintf(
                    'Colonne "%s" manquante. Le fichier doit être un export Excel de l\'Espace Tri (FFTri).',
                    $required,
                ));
                $this->em->rollback();
                return $result;
            }
        }

        $records = (new Statement())->process($csv);
        $line = 1;
        /** @var array<int, array{user: User, isRenewal: bool}> $welcomeCandidates */
        $welcomeCandidates = [];

        foreach ($records as $record) {
            $line++;

            try {
                $numLicence = trim($this->resolveCol($record, self::COL_NUM_LICENCE));
                if ($numLicence === '') {
                    $result->addError($line, 'Numéro de licence vide', $record);
                    continue;
                }

                $email = $this->cleanEmail($this->resolveCol($record, self::COL_EMAIL));
                $emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
                // L'email du CSV n'est CRITIQUE qu'à la création d'un nouveau
                // compte (sinon on ne saurait pas qui notifier). Pour un
                // adhérent existant on garde l'email local (cf. setEmail
                // conditionné plus bas) — un email manquant dans le CSV
                // n'est donc plus bloquant.

                // Statut : ancien format FFTri seulement. Nouveau format =
                // pas de colonne Statut, tous les adhérents importés sont
                // considérés actifs (leur présence dans le CSV = actif).
                $statutRaw = trim($this->resolveCol($record, self::COL_STATUT));
                $isActive = $statutRaw !== '' ? $this->isStatutActive($statutRaw) : true;
                $statut = $statutRaw !== '' ? $statutRaw : 'Validé';

                $typeLicenceRaw = $this->resolveCol($record, self::COL_TYPE_LICENCE);
                // Profil principal calculé depuis la date de naissance
                // (≤ 18 ans dans l'année courante = Jeune), fallback sur le
                // type de licence FFTri si pas de date.
                $dateNaissance = $this->parseDate($this->resolveCol($record, self::COL_DATE_NAISSANCE));
                if ($dateNaissance !== null) {
                    $principalProfile = Profile::principalFromBirthDate($dateNaissance, $importedAt);
                } else {
                    $principalProfile = stripos($typeLicenceRaw, 'jeune') !== false
                        ? Profile::Jeune
                        : Profile::Senior;
                }

                // Mobile prime sur Téléphone (ancien FFTri) ; nouveau CSV
                // n'a plus que Téléphone.
                $tel = $this->cleanPhone($this->resolveCol($record, self::COL_MOBILE));
                if ($tel === '') {
                    $tel = $this->cleanPhone($this->resolveCol($record, self::COL_TELEPHONE));
                }

                $user = $this->users->findOneByNumLicence($numLicence);
                $isNew = $user === null;

                if ($isNew) {
                    // Création : on a besoin d'un email valide, sinon impossible
                    // de créer un compte exploitable.
                    if (!$emailValid) {
                        $result->addError($line, sprintf('Email invalide ou manquant (licence %s) — création impossible', $numLicence), $record);
                        continue;
                    }
                    $user = new User();
                    $user->setNumLicence($numLicence);
                    $user->setType(UserType::Adherent);
                    $user->setSubType(User::SUBTYPE_CLUB);
                    $user->setRole('user');
                }

                // "Nom d'usage" (optionnel FFTri) prime sur "Nom" quand présent :
                // couvre les cas nom marital, pseudonyme, etc. La colonne peut
                // être absente des CSV plus anciens — dans ce cas on retombe
                // silencieusement sur "Nom".
                $nomLegal = trim($this->resolveCol($record, self::COL_NOM));
                $nomUsage = trim($this->resolveCol($record, self::COL_NOM_USAGE));
                $user->setNom($nomUsage !== '' ? $nomUsage : $nomLegal);
                $user->setPrenom(trim($this->resolveCol($record, self::COL_PRENOM)));
                // Email : posé UNIQUEMENT à la création. Sur un re-import,
                // on conserve l'email actuellement en base — les admins le
                // corrigent souvent en local (alias, domaine personnel, etc.)
                // et un import ré-écraserait sinon ces ajustements à chaque
                // sync FFTri.
                if ($isNew) {
                    $user->setEmail($email);
                }
                $user->setTelephone($tel !== '' ? $tel : null);
                $user->setStatutLicence($statut);
                $user->setIsActive($isActive);
                $user->setLastCsvSyncAt($importedAt);

                // Sync profiles : remplace Jeune/Senior par le bon, garde les
                // profils manuels (Parent, Entraîneur, Encadrant) intacts.
                $existingProfiles = array_filter(
                    $user->getProfiles(),
                    fn (string $p) => !in_array($p, [Profile::Jeune->value, Profile::Senior->value], true),
                );
                $existingProfiles[] = $principalProfile->value;
                $user->setProfiles(array_values($existingProfiles));

                // Nouveaux champs FFTri
                $user->setDateNaissance($dateNaissance);
                $user->setSexe($this->cleanSexe($this->resolveCol($record, self::COL_SEXE)));
                $user->setAdresse($this->buildAdresse($record));
                $user->setTypeLicence(self::normalizeTypeLicence($typeLicenceRaw));
                $user->setCategorieAge(trim($this->resolveCol($record, self::COL_CATEGORIE_AGE)) ?: null);

                $errors = $this->validator->validate($user);
                if (count($errors) > 0) {
                    $msgs = [];
                    foreach ($errors as $err) {
                        $msgs[] = $err->getPropertyPath().': '.$err->getMessage();
                    }
                    $result->addError($line, implode('; ', $msgs), $record);
                    continue;
                }

                if ($isNew) {
                    $this->em->persist($user);
                    $result->created++;
                } else {
                    $result->updated++;
                }

                // Trace l'adhésion pour la saison sélectionnée (statistiques
                // historiques). Upsert : crée si absente, met à jour les
                // snapshots licence + updatedAt sinon.
                // Retourne true si une NOUVELLE membership a été créée →
                // l'user est candidat à un email de bienvenue (nouveau OU
                // adhérent existant renouvelant après une saison manquée).
                $freshMembership = false;
                if ($season !== null) {
                    $freshMembership = $this->upsertMembership($user, $season, $importedAt, $record);
                }
                if ($isNew || $freshMembership) {
                    // isRenewal = compte existant ET nouveau membership pour la saison
                    // (donc adhérent connu qui revient). Un compte fraîchement créé
                    // reste « new » même s'il a une membership à sa création.
                    $welcomeCandidates[] = [
                        'user' => $user,
                        'isRenewal' => !$isNew,
                    ];
                }
            } catch (\Throwable $e) {
                $result->addError($line, $e->getMessage(), $record);
                $this->csvImportLogger->error('CSV row error', ['line' => $line, 'exception' => $e]);
            }
        }

        $this->em->flush();

        // Lier les profils partageant un même e-mail (parent + enfants).
        // Pour chaque groupe email avec >1 user, le plus âgé devient primaire,
        // les autres pointent vers lui via linkedToUser.
        $this->linkSharedEmailProfiles();
        $this->em->flush();

        // Désactivation des users absents de cet import.
        // Si une période de grâce est active (début de saison), on ne désactive
        // PAS — les anciens adhérents non encore renouvelés restent connectables
        // jusqu'à la date limite.
        $settings = $this->membership->findCurrent();
        $inGrace = $settings !== null && $settings->isInOldMembersGracePeriod();
        $stale = $this->users->findActiveNotSyncedSince($importedAt);

        if ($inGrace) {
            $result->deactivationDeferred = count($stale);
            $result->gracePeriodUntil = $settings->getOldMembersValidUntil();
            $this->csvImportLogger->info('CSV import : désactivations différées', [
                'count' => $result->deactivationDeferred,
                'until' => $result->gracePeriodUntil?->format('Y-m-d'),
            ]);
        } else {
            foreach ($stale as $u) {
                $u->setIsActive(false);
                $result->deactivated++;
            }
            $this->em->flush();
        }

        // Réconcilie les parents externes selon la présence d'enfants actifs.
        //  - 0 enfant actif  → parent désactivé (perte d'accès mobile)
        //  - ≥ 1 actif       → parent réactivé (retour d'un enfant qui
        //    renouvelle sa licence)
        // Les parents ADHÉRENTS (avec leur propre licence) sont gérés par
        // la logique deactivation ci-dessus (findActiveNotSyncedSince) et
        // pas concernés par ce bloc.
        foreach ($this->users->findExternalParents() as $parent) {
            $activeChildren = 0;
            foreach ($parent->getChildren() as $c) {
                if ($c->isActive()) { $activeChildren++; }
            }
            $wasActive = $parent->isActive();
            $shouldBeActive = $activeChildren > 0;
            if ($wasActive && !$shouldBeActive) {
                $parent->setIsActive(false);
                $result->externalParentsDeactivated++;
            } elseif (!$wasActive && $shouldBeActive) {
                $parent->setIsActive(true);
                $result->externalParentsReactivated++;
            }
        }
        $this->em->flush();

        // Dedup + comptage des candidats bienvenue (dry-run compté, dispatch
        // effectif hors dry-run + case cochée). Fait AVANT rollback pour
        // que le résultat affiché soit fidèle en simulation.
        // Si un user apparaît deux fois : le premier détermine son kind
        // (dedup par uid).
        $uniqueCandidates = [];
        $seen = [];
        foreach ($welcomeCandidates as $entry) {
            $uid = $entry['user']->getId();
            if ($uid === null || isset($seen[$uid])) continue;
            $seen[$uid] = true;
            $uniqueCandidates[] = $entry;
        }

        if ($dryRun) {
            // Aperçu : compte ce qui SERAIT envoyé mais ne dispatch rien
            // (aucun email ni push effectif), et rollback toutes les
            // modifications DB.
            if ($sendWelcomeEmails) {
                $result->welcomeEmailsSent = count($uniqueCandidates);
            }
            $this->em->rollback();
            $this->em->clear();
            $this->csvImportLogger->info('CSV import terminé (DRY-RUN)', $result->toArray());
            return $result;
        }

        // Import réel : on commit puis on dispatch les emails de bienvenue.
        $this->em->commit();

        // Email de bienvenue envoyé à chaque adhérent qui rejoint une (nouvelle)
        // saison. Template distinct selon nouveau/renouvellement (isRenewal).
        // Le backfill (app:memberships:backfill) ne passe pas par ici —
        // pas de spam pour des adhésions rétroactives.
        if ($sendWelcomeEmails) {
            foreach ($uniqueCandidates as $entry) {
                $u = $entry['user'];
                $issued = $this->magicLinks->issue($u);
                $this->bus->dispatch(new SendMagicLinkEmailMessage(
                    userId: $u->getId(),
                    clearToken: $issued['token'],
                    isWelcome: true,
                    isRenewal: $entry['isRenewal'],
                ));
                $result->welcomeEmailsSent++;
            }
        }

        $this->csvImportLogger->info('CSV import terminé', $result->toArray());
        return $result;
    }

    /**
     * Crée (ou met à jour) le lien UserSeasonMembership pour l'adhésion de
     * cet utilisateur à cette saison. Rejouable : deux imports successifs
     * pour la même (user, saison) rafraîchissent les snapshots licence
     * mais ne dupliquent pas la ligne.
     *
     * @param  array<string, mixed> $record  ligne CSV brute (pour snapshots)
     * @return bool  true si une NOUVELLE membership vient d'être créée
     *               (permet au caller de déclencher l'email de bienvenue)
     */
    private function upsertMembership(User $user, TrainingSeason $season, \DateTimeImmutable $importedAt, array $record): bool
    {
        // Un user tout juste créé (getId === null avant flush) ne peut pas
        // encore avoir de membership existant. Sinon on cherche en base.
        $existing = $user->getId() !== null
            ? $this->memberships->findOneByUserAndSeason($user, $season)
            : null;

        $membership = $existing ?? new UserSeasonMembership($user, $season);
        if ($existing !== null) {
            $membership->touchUpdatedAt();
        }
        $membership->setStatutLicence(trim($this->resolveCol($record, self::COL_STATUT)) ?: null);
        // Snapshot NORMALISÉ (Compétition / Loisir / Dirigeant / null) —
        // sinon la valeur brute FFTri (« Loisir 2026-27 - Sénior », etc.)
        // ne matcherait aucune catégorie côté stats.
        $membership->setTypeLicence(self::normalizeTypeLicence($this->resolveCol($record, self::COL_TYPE_LICENCE)));
        $membership->setCategorieAge(trim($this->resolveCol($record, self::COL_CATEGORIE_AGE)) ?: null);

        if ($existing === null) {
            $this->em->persist($membership);
            return true;
        }
        return false;
    }

    private function cleanEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Nettoie un numéro de téléphone :
     *  - supprime les espaces et tirets
     *  - ajoute un 0 devant si le numéro fait 9 chiffres (Excel coupe parfois
     *    le 0 de tête, donnant "612345678" au lieu de "0612345678")
     */
    private function cleanPhone(string $phone): string
    {
        $cleaned = (string) preg_replace('/[\s.\-]+/', '', trim($phone));
        if ($cleaned === '') {
            return '';
        }
        // Numéro français mal formaté (9 chiffres sans 0 initial) → préfixer
        if (preg_match('/^\d{9}$/', $cleaned)) {
            $cleaned = '0'.$cleaned;
        }
        return $cleaned;
    }

    /**
     * Lit une valeur en essayant tous les alias possibles de la colonne
     * canonique. Comparaison tolérante (accents, casse, BOM, espaces
     * multiples) pour absorber les petites variations d'exports FFTri.
     *
     * @param array<string, mixed> $record
     */
    private function resolveCol(array $record, string $canonical): string
    {
        $candidates = self::COL_ALIASES[$canonical] ?? [$canonical];
        $normalizedCandidates = [];
        foreach ($candidates as $c) {
            $normalizedCandidates[self::normalizeHeader($c)] = true;
        }
        foreach ($record as $key => $value) {
            if (isset($normalizedCandidates[self::normalizeHeader((string) $key)])) {
                return (string) ($value ?? '');
            }
        }
        return '';
    }

    /**
     * Normalise un nom de colonne pour comparaison tolérante :
     * trim + suppression du BOM UTF-8 + minuscule + suppression des
     * accents. « Numéro de licence », « Numero de licence », «  N°Licence »
     * (ancien nom) → clés respectivement 'numero de licence' / 'numero de
     * licence' / 'n°licence' pour matching prévisible.
     */
    private static function normalizeHeader(string $h): string
    {
        // Retire BOM UTF-8 en tête si présent (fichiers Windows exportés
        // par certains outils, non nettoyés par skipInputBOM sur les
        // premières clés).
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
        $h = trim($h);
        // Compact les espaces multiples en 1
        $h = (string) preg_replace('/\s+/u', ' ', $h);
        $h = mb_strtolower($h, 'UTF-8');
        // Translittère les accents (é → e, à → a, etc.). //TRANSLIT nécessite
        // iconv linké avec libiconv complet ; en cas d'échec on garde la
        // chaîne originale plutôt que de casser le matching.
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h);
        return $translit !== false && $translit !== '' ? $translit : $h;
    }

    /**
     * Test rapide : le fichier est-il en UTF-8 valide ?
     * Lit les premiers 64 Ko (largement au-dessus de la première ligne
     * dans tous les CSV FFTri) et vérifie que la séquence est légale.
     * Retourne true par défaut si la lecture échoue (n'introduit pas
     * de conversion incorrecte en cas d'incertitude).
     */
    private function isValidUtf8(string $filePath): bool
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return true;
        }
        $chunk = fread($handle, 65536);
        fclose($handle);
        if ($chunk === false) {
            return true;
        }
        return mb_check_encoding($chunk, 'UTF-8');
    }

    /**
     * Détecte le délimiteur en peekant la première ligne du fichier.
     * FFTri exporte tantôt en CSV (`,` ou `;`), tantôt en TSV (`\t`).
     * On garde le séparateur qui produit le plus de colonnes (heuristique
     * suffisante : les en-têtes contiennent 10+ colonnes). Fallback = valeur
     * passée en paramètre (rétro-compat des appels explicites).
     */
    private function detectDelimiter(string $filePath, string $fallback): string
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return $fallback;
        }
        $firstLine = fgets($handle, 8192);
        fclose($handle);
        if ($firstLine === false) {
            return $fallback;
        }
        $best = $fallback;
        $bestCount = 0;
        foreach (["\t", ';', ','] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }
        // Sécurité : si la ligne n'a aucun séparateur reconnu, garde le fallback.
        return $bestCount >= 2 ? $best : $fallback;
    }

    private function cleanSexe(string $s): ?string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        if ($s === 'm' || $s === 'h' || $s === 'homme' || $s === 'masculin') {
            return 'm';
        }
        if ($s === 'f' || $s === 'femme' || $s === 'feminin' || $s === 'féminin') {
            return 'f';
        }
        return null;
    }

    /**
     * Parse les dates FFTri au format français DD/MM/YYYY.
     */
    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
        return $d !== false ? $d : null;
    }

    /**
     * Compose l'adresse pour stockage :
     *  1. Nouveau format FFTri : colonne unique « Adresse » (ex :
     *     « 15 IMPASSE LOUISA PAULIN, 31200 TOULOUSE, France »).
     *     On split sur « , » pour retrouver un affichage multi-lignes.
     *  2. Ancien format : concatène les 6 colonnes (Adresse principale,
     *     Adresse Détails, Lieu-dit, CP, Ville, Pays).
     */
    private function buildAdresse(array $record): ?string
    {
        // Nouveau format : colonne unique.
        $single = trim($this->resolveCol($record, self::COL_ADRESSE));
        if ($single !== '') {
            // Split sur virgule-espace, filtre les segments vides,
            // supprime le pays trailing s'il vaut « France » (bruit).
            $parts = array_values(array_filter(
                array_map('trim', explode(',', $single)),
                fn ($s) => $s !== '',
            ));
            if (count($parts) > 0 && strcasecmp((string) end($parts), 'France') === 0) {
                array_pop($parts);
            }
            return count($parts) > 0 ? implode("\n", $parts) : null;
        }

        // Ancien format : 6 colonnes séparées.
        $line1 = trim((string) ($record[self::COL_ADRESSE_PRINCIPALE] ?? ''));
        $line2 = trim((string) ($record[self::COL_ADRESSE_DETAILS] ?? ''));
        $line3 = trim((string) ($record[self::COL_LIEU_DIT] ?? ''));
        $cp = trim((string) ($record[self::COL_CODE_POSTAL] ?? ''));
        $ville = trim((string) ($record[self::COL_VILLE] ?? ''));
        $pays = trim((string) ($record[self::COL_PAYS] ?? ''));

        $parts = array_filter([$line1, $line2, $line3], fn ($s) => $s !== '');
        $cpVille = trim($cp.' '.$ville);

        $address = implode("\n", $parts);
        if ($cpVille !== '') {
            $address = trim($address."\n".$cpVille);
        }
        if ($pays !== '' && strcasecmp($pays, 'France') !== 0) {
            $address .= "\n".$pays;
        }
        return $address !== '' ? $address : null;
    }

    /**
     * Catégorise un "Type de licence" FFTri en :
     *  - "Dirigeant"   si le libellé contient "dirigeant"
     *  - "Compétition" si "compétition"
     *  - "Loisir"      si "loisir"
     *  - null sinon
     */
    public static function normalizeTypeLicence(string $raw): ?string
    {
        $lower = mb_strtolower($raw, 'UTF-8');
        if (str_contains($lower, 'dirigeant')) {
            return 'Dirigeant';
        }
        if (str_contains($lower, 'compétition') || str_contains($lower, 'competition')) {
            return 'Compétition';
        }
        if (str_contains($lower, 'loisir')) {
            return 'Loisir';
        }
        return null;
    }

    /**
     * Pour chaque e-mail partagé par plusieurs users actifs, le plus âgé est
     * désigné comme primaire (linkedToUser=null), les autres pointent vers lui.
     * Idempotent : peut être rejoué sans casser les liens existants.
     */
    private function linkSharedEmailProfiles(): void
    {
        $sql = "
            SELECT email
            FROM `user`
            WHERE is_active = 1 AND email IS NOT NULL AND email != ''
            GROUP BY email
            HAVING COUNT(*) > 1
        ";
        $sharedEmails = $this->em->getConnection()->fetchFirstColumn($sql);

        foreach ($sharedEmails as $email) {
            $usersInGroup = $this->users->findAllActiveByEmail($email);
            if (count($usersInGroup) < 2) {
                continue;
            }

            // Trier : le plus âgé (date naissance la + ancienne) en tête.
            // Les users sans date de naissance vont en queue.
            usort($usersInGroup, function (User $a, User $b) {
                $da = $a->getDateNaissance();
                $db = $b->getDateNaissance();
                if ($da === null && $db === null) {
                    return $a->getId() <=> $b->getId();
                }
                if ($da === null) {
                    return 1;
                }
                if ($db === null) {
                    return -1;
                }
                return $da <=> $db;
            });

            $primary = array_shift($usersInGroup);
            $primary->setLinkedToUser(null);

            foreach ($usersInGroup as $dependent) {
                // Évite l'auto-référence
                if ($dependent->getId() !== $primary->getId()) {
                    $dependent->setLinkedToUser($primary);
                }
            }
        }
    }

    private function isStatutActive(string $statut): bool
    {
        $normalized = mb_strtolower(trim($statut), 'UTF-8');
        return in_array($normalized, [
            'valide', 'validé', 'valid',
            'actif', 'active',
            'a jour', 'à jour',
            'en cours',
        ], true);
    }
}
