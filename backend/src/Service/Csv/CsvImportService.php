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

/**
 * Import des adhérents depuis un export "Excel" de l'Espace Tri (FFTri).
 *
 * Le fichier exporté contient 60 colonnes en français accentué. Seules
 * quelques colonnes sont utilisées par l'application :
 *
 *   - "Numéro de licence" → identifiant unique
 *   - "Nom" / "Prénom"
 *   - "Email"
 *   - "Mobile" (fallback : "Téléphone")
 *   - "Statut" (Validé = actif)
 *   - "Type de licence" (contient "Jeune" → catégorie Jeune, sinon Sénior)
 */
class CsvImportService
{
    private const COL_NUM_LICENCE = 'Numéro de licence';
    private const COL_NOM = 'Nom';
    private const COL_PRENOM = 'Prénom';
    private const COL_EMAIL = 'Email';
    private const COL_MOBILE = 'Mobile';
    private const COL_TELEPHONE = 'Téléphone';
    private const COL_STATUT = 'Statut';
    private const COL_TYPE_LICENCE = 'Type de licence';

    /**
     * Colonnes requises pour valider que le fichier est bien un export FFTri.
     */
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

                // Catégorie : dérivée du "Type de licence" qui contient
                // explicitement "Jeune" pour toutes les catégories jeunes
                // (Mini-Poussin → Junior).
                $typeLicence = (string) ($record[self::COL_TYPE_LICENCE] ?? '');
                $categorie = stripos($typeLicence, 'jeune') !== false
                    ? UserCategory::Jeune
                    : UserCategory::Senior;

                // Téléphone : Mobile en priorité, sinon ligne fixe
                $tel = $this->cleanPhone((string) ($record[self::COL_MOBILE] ?? ''));
                if ($tel === '') {
                    $tel = $this->cleanPhone((string) ($record[self::COL_TELEPHONE] ?? ''));
                }

                $user = $this->users->findOneByNumLicence($numLicence);
                $isNew = $user === null;

                if ($isNew) {
                    $user = new User();
                    $user->setNumLicence($numLicence);
                    $user->setRoles(['ROLE_USER']);
                }

                $user->setNom(trim((string) ($record[self::COL_NOM] ?? '')));
                $user->setPrenom(trim((string) ($record[self::COL_PRENOM] ?? '')));
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

        // Envoi des mails de bienvenue (async)
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
     * Supprime les espaces des téléphones (FFTri formate "06 12 34 56 78 ").
     */
    private function cleanPhone(string $phone): string
    {
        return (string) preg_replace('/\s+/', '', trim($phone));
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
