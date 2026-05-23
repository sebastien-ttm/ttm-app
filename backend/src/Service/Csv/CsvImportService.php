<?php

namespace App\Service\Csv;

use App\Entity\User;
use App\Enum\UserCategory;
use App\Message\SendMagicLinkEmailMessage;
use App\Repository\UserRepository;
use App\Service\MagicLinkService;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use League\Csv\Statement;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CsvImportService
{
    /**
     * Schémas de colonnes supportés.
     *
     *  - "simple" : notre format minimaliste (num_licence,nom,prenom,email,telephone,categorie,statut_licence)
     *  - "fftri"  : export "Excel" depuis l'Espace Tri (60 colonnes, headers en français accentués)
     */
    private const SCHEMAS = [
        'simple' => [
            'detect' => 'num_licence',
            'map' => [
                'num_licence' => 'num_licence',
                'nom' => 'nom',
                'prenom' => 'prenom',
                'email' => 'email',
                'telephone' => 'telephone',
                'statut_licence' => 'statut_licence',
                'categorie_explicit' => 'categorie',
            ],
        ],
        'fftri' => [
            'detect' => 'numero de licence',
            'map' => [
                'num_licence' => 'Numéro de licence',
                'nom' => 'Nom',
                'prenom' => 'Prénom',
                'email' => 'Email',
                'telephone' => 'Mobile',
                'telephone_fallback' => 'Téléphone',
                'statut_licence' => 'Statut',
                'type_licence' => 'Type de licence',
            ],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ValidatorInterface $validator,
        private readonly MagicLinkService $magicLinks,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $csvImportLogger,
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

        $rawHeaders = $csv->getHeader();
        $schema = $this->detectSchema($rawHeaders);

        if ($schema === null) {
            $result->addError(0, 'Format CSV non reconnu. Attendu : export FFTri (Espace Tri) ou format simple (num_licence,nom,prenom,email,...).');
            return $result;
        }

        $this->csvImportLogger->info('CSV schema détecté', ['schema' => $schema['name']]);

        $records = (new Statement())->process($csv);
        $line = 1;
        $newUsers = [];

        foreach ($records as $record) {
            $line++;

            try {
                $numLicence = trim((string) ($record[$schema['map']['num_licence']] ?? ''));
                if ($numLicence === '') {
                    $result->addError($line, 'Numéro de licence vide', $record);
                    continue;
                }

                $email = $this->cleanEmail((string) ($record[$schema['map']['email']] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result->addError($line, sprintf('Email invalide ou manquant (licence %s)', $numLicence), $record);
                    continue;
                }

                $statut = trim((string) ($record[$schema['map']['statut_licence']] ?? 'Actif'));
                $isActive = $this->isStatutActive($statut);

                // Catégorie : soit colonne explicite (format simple), soit dérivée du
                // "Type de licence" qui contient "Jeune" pour les catégories jeunes.
                if (isset($schema['map']['categorie_explicit'])) {
                    $categorie = UserCategory::fromCsv((string) ($record[$schema['map']['categorie_explicit']] ?? 'senior'));
                } else {
                    $typeLicence = (string) ($record[$schema['map']['type_licence']] ?? '');
                    $categorie = stripos($typeLicence, 'jeune') !== false
                        ? UserCategory::Jeune
                        : UserCategory::Senior;
                }

                // Téléphone : Mobile en priorité, sinon Téléphone fixe
                $tel = $this->cleanPhone((string) ($record[$schema['map']['telephone']] ?? ''));
                if ($tel === '' && isset($schema['map']['telephone_fallback'])) {
                    $tel = $this->cleanPhone((string) ($record[$schema['map']['telephone_fallback']] ?? ''));
                }

                $user = $this->users->findOneByNumLicence($numLicence);
                $isNew = $user === null;

                if ($isNew) {
                    $user = new User();
                    $user->setNumLicence($numLicence);
                    $user->setRoles(['ROLE_USER']);
                }

                $user->setNom(trim((string) ($record[$schema['map']['nom']] ?? '')));
                $user->setPrenom(trim((string) ($record[$schema['map']['prenom']] ?? '')));
                $user->setEmail($email);
                $user->setTelephone($tel !== '' ? $tel : null);
                $user->setCategorie($categorie);
                $user->setStatutLicence($statut);
                $user->setIsActive($isActive);
                $user->setLastCsvSyncAt($importedAt);

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

        // Désactivation des users absents de cet import
        $stale = $this->users->findActiveNotSyncedSince($importedAt);
        foreach ($stale as $u) {
            $u->setIsActive(false);
            $result->deactivated++;
        }
        $this->em->flush();

        // Envoi des mails de bienvenue
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

    /**
     * Détecte le format CSV en se basant sur les colonnes présentes.
     *
     * @param list<string> $rawHeaders
     * @return array{name: string, map: array<string, string>}|null
     */
    private function detectSchema(array $rawHeaders): ?array
    {
        $normalizedHeaders = array_map([$this, 'normalizeHeader'], $rawHeaders);

        foreach (self::SCHEMAS as $name => $schema) {
            if (in_array($schema['detect'], $normalizedHeaders, true)) {
                return ['name' => $name, 'map' => $schema['map']];
            }
        }
        return null;
    }

    private function normalizeHeader(string $h): string
    {
        $h = trim($h);
        $h = mb_strtolower($h, 'UTF-8');
        // strip accents
        if (function_exists('iconv')) {
            $h = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h) ?: $h;
        }
        return $h;
    }

    private function cleanEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Supprime les espaces et caractères non numériques d'un téléphone
     * (les exports FFTri ont des formats comme "06 12 34 56 78 ").
     */
    private function cleanPhone(string $phone): string
    {
        $cleaned = preg_replace('/\s+/', '', trim($phone));
        return $cleaned ?? '';
    }

    private function isStatutActive(string $statut): bool
    {
        $normalized = mb_strtolower(trim($statut), 'UTF-8');
        return in_array($normalized, [
            'actif', 'active',
            'a jour', 'à jour',
            'valide', 'validé', 'valid',
            'en cours',
        ], true);
    }
}
