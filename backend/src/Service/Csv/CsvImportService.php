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
    private const COL_SEXE = 'Sexe';
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

    private const REQUIRED_COLUMNS = [
        self::COL_NUM_LICENCE,
        self::COL_NOM,
        self::COL_PRENOM,
        self::COL_EMAIL,
        self::COL_STATUT,
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

        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setDelimiter($delimiter);
        $csv->setHeaderOffset(0);
        $csv->skipInputBOM();

        $headers = $csv->getHeader();

        foreach (self::REQUIRED_COLUMNS as $required) {
            if (!in_array($required, $headers, true)) {
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
                $numLicence = trim((string) ($record[self::COL_NUM_LICENCE] ?? ''));
                if ($numLicence === '') {
                    $result->addError($line, 'Numéro de licence vide', $record);
                    continue;
                }

                $email = $this->cleanEmail((string) ($record[self::COL_EMAIL] ?? ''));
                $emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
                // L'email du CSV n'est CRITIQUE qu'à la création d'un nouveau
                // compte (sinon on ne saurait pas qui notifier). Pour un
                // adhérent existant on garde l'email local (cf. setEmail
                // conditionné plus bas) — un email manquant dans le CSV
                // n'est donc plus bloquant.

                $statut = trim((string) ($record[self::COL_STATUT] ?? 'Validé'));
                $isActive = $this->isStatutActive($statut);

                $typeLicenceRaw = (string) ($record[self::COL_TYPE_LICENCE] ?? '');
                // Profil principal calculé depuis la date de naissance
                // (≤ 18 ans dans l'année courante = Jeune), fallback sur le
                // type de licence FFTri si pas de date.
                $dateNaissance = $this->parseDate((string) ($record[self::COL_DATE_NAISSANCE] ?? ''));
                if ($dateNaissance !== null) {
                    $principalProfile = Profile::principalFromBirthDate($dateNaissance, $importedAt);
                } else {
                    $principalProfile = stripos($typeLicenceRaw, 'jeune') !== false
                        ? Profile::Jeune
                        : Profile::Senior;
                }

                $tel = $this->cleanPhone((string) ($record[self::COL_MOBILE] ?? ''));
                if ($tel === '') {
                    $tel = $this->cleanPhone((string) ($record[self::COL_TELEPHONE] ?? ''));
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
                $nomLegal = trim((string) ($record[self::COL_NOM] ?? ''));
                $nomUsage = trim((string) ($record[self::COL_NOM_USAGE] ?? ''));
                $user->setNom($nomUsage !== '' ? $nomUsage : $nomLegal);
                $user->setPrenom(trim((string) ($record[self::COL_PRENOM] ?? '')));
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
                $user->setSexe($this->cleanSexe((string) ($record[self::COL_SEXE] ?? '')));
                $user->setAdresse($this->buildAdresse($record));
                $user->setTypeLicence(self::normalizeTypeLicence($typeLicenceRaw));
                $user->setCategorieAge(trim((string) ($record[self::COL_CATEGORIE_AGE] ?? '')) ?: null);

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
        $membership->setStatutLicence(trim((string) ($record['Statut'] ?? '')) ?: null);
        // Snapshot NORMALISÉ (Compétition / Loisir / Dirigeant / null) —
        // sinon la valeur brute FFTri (« Loisir 2026-27 - Sénior », etc.)
        // ne matcherait aucune catégorie côté stats.
        $membership->setTypeLicence(self::normalizeTypeLicence((string) ($record['Type de licence'] ?? '')));
        $membership->setCategorieAge(trim((string) ($record['Catégorie d\'âge'] ?? '')) ?: null);

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
     * Concatène les composantes d'adresse en une seule chaîne lisible.
     */
    private function buildAdresse(array $record): ?string
    {
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
