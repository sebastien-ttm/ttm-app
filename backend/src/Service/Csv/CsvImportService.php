<?php

namespace App\Service\Csv;

use App\Entity\User;
use App\Enum\Profile;
use App\Enum\UserType;
use App\Message\SendMagicLinkEmailMessage;
use App\Repository\MembershipSettingsRepository;
use App\Repository\UserRepository;
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
    ) {
    }

    public function import(string $filePath, bool $sendWelcomeEmails = true, string $delimiter = ','): CsvImportResult
    {
        $result = new CsvImportResult();
        $importedAt = new \DateTimeImmutable();

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
                return $result;
            }
        }

        $records = (new Statement())->process($csv);
        $line = 1;
        $newUsers = [];

        foreach ($records as $record) {
            $line++;

            try {
                $numLicence = trim((string) ($record[self::COL_NUM_LICENCE] ?? ''));
                if ($numLicence === '') {
                    $result->addError($line, 'Numéro de licence vide', $record);
                    continue;
                }

                $email = $this->cleanEmail((string) ($record[self::COL_EMAIL] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result->addError($line, sprintf('Email invalide ou manquant (licence %s)', $numLicence), $record);
                    continue;
                }

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
                    $user = new User();
                    $user->setNumLicence($numLicence);
                    $user->setType(UserType::Adherent);
                    $user->setRole('user');
                }

                $user->setNom(trim((string) ($record[self::COL_NOM] ?? '')));
                $user->setPrenom(trim((string) ($record[self::COL_PRENOM] ?? '')));
                $user->setEmail($email);
                $user->setTelephone($tel !== '' ? $tel : null);
                $user->setStatutLicence($statut);
                $user->setIsActive($isActive);
                $user->setLastCsvSyncAt($importedAt);

                // Sync profiles : remplace Jeune/Senior par le bon, garde les
                // profils manuels (U25, Parent, Entraîneur, Encadrant) intacts.
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
                $user->setTypeLicence($this->normalizeTypeLicence($typeLicenceRaw));
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
                    $newUsers[] = $user;
                } else {
                    $result->updated++;
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

        if ($sendWelcomeEmails) {
            foreach ($newUsers as $u) {
                $issued = $this->magicLinks->issue($u);
                $this->bus->dispatch(new SendMagicLinkEmailMessage(
                    userId: $u->getId(),
                    clearToken: $issued['token'],
                    isWelcome: true,
                ));
            }
        }

        $this->csvImportLogger->info('CSV import terminé', $result->toArray());
        return $result;
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
    private function normalizeTypeLicence(string $raw): ?string
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
