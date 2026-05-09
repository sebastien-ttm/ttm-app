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
    private const REQUIRED_HEADERS = [
        'num_licence', 'nom', 'prenom', 'email', 'categorie', 'statut_licence',
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

        // Strip BOM if present
        $csv->skipInputBOM();

        $headers = array_map(
            fn ($h) => mb_strtolower(trim((string) $h), 'UTF-8'),
            $csv->getHeader()
        );

        foreach (self::REQUIRED_HEADERS as $required) {
            if (!in_array($required, $headers, true)) {
                $result->addError(0, sprintf('Colonne manquante : "%s"', $required));
                return $result;
            }
        }

        $records = (new Statement())->process($csv);
        $line = 1;
        $newUsers = [];

        foreach ($records as $record) {
            $line++;
            $row = $this->normalizeRow($record);

            try {
                $numLicence = trim((string) ($row['num_licence'] ?? ''));
                if ($numLicence === '') {
                    $result->addError($line, 'num_licence vide', $row);
                    continue;
                }

                $email = trim((string) ($row['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result->addError($line, 'Email invalide ou manquant', $row);
                    continue;
                }

                $statut = trim((string) ($row['statut_licence'] ?? 'Actif'));
                $isActive = $this->isStatutActive($statut);

                $user = $this->users->findOneByNumLicence($numLicence);
                $isNew = $user === null;

                if ($isNew) {
                    $user = new User();
                    $user->setNumLicence($numLicence);
                    $user->setRoles(['ROLE_USER']);
                }

                $user->setNom(trim((string) ($row['nom'] ?? '')));
                $user->setPrenom(trim((string) ($row['prenom'] ?? '')));
                $user->setEmail($email);
                $user->setTelephone(trim((string) ($row['telephone'] ?? '')) ?: null);
                $user->setCategorie(UserCategory::fromCsv((string) ($row['categorie'] ?? 'senior')));
                $user->setStatutLicence($statut);
                $user->setIsActive($isActive);
                $user->setLastCsvSyncAt($importedAt);

                $errors = $this->validator->validate($user);
                if (count($errors) > 0) {
                    $msgs = [];
                    foreach ($errors as $err) {
                        $msgs[] = $err->getPropertyPath().': '.$err->getMessage();
                    }
                    $result->addError($line, implode('; ', $msgs), $row);
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
                $result->addError($line, $e->getMessage(), $row);
                $this->csvImportLogger->error('CSV row error', ['line' => $line, 'exception' => $e]);
            }
        }

        $this->em->flush();

        // Deactivate users not touched by this import
        $stale = $this->users->findActiveNotSyncedSince($importedAt);
        foreach ($stale as $u) {
            $u->setIsActive(false);
            $result->deactivated++;
        }
        $this->em->flush();

        // Welcome emails for new users (after flush so ids exist)
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

        $this->csvImportLogger->info('CSV import done', $result->toArray());
        return $result;
    }

    /**
     * @param array<string, string> $row
     * @return array<string, string>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $k => $v) {
            $key = mb_strtolower(trim((string) $k), 'UTF-8');
            $normalized[$key] = trim((string) $v);
        }
        return $normalized;
    }

    private function isStatutActive(string $statut): bool
    {
        $normalized = mb_strtolower(trim($statut), 'UTF-8');
        return in_array($normalized, ['actif', 'active', 'a jour', 'à jour', 'valide', 'valid'], true);
    }
}
