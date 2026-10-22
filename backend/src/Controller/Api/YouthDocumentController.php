<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\YouthDocument;
use App\Enum\YouthDocumentType;
use App\Repository\UserRepository;
use App\Repository\YouthDocumentRepository;
use App\Service\AvatarService;
use App\Service\YouthDocument\YouthDocumentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Documents officiels des adhérents jeunes : fiche sanitaire et autorisation de sortie du territoire (voir YouthDocument).
 *
 * Données sensibles (santé, identité) : accès limité au jeune lui-même, à ses parents (comptes parents rattachés, ou compte
 * principal de l'e-mail partagé), au staff (entraîneurs, encadrants) et aux administrateurs. Pour tout autre compte, le jeune
 * est « introuvable ». Le staff dispose d'une vue d'ensemble (GET /api/staff/youth) pour repérer les documents manquants
 * et ouvrir un document en quelques gestes ; il voit aussi, sur la fiche d'un jeune, ses numéros et son contact d'urgence.
 */
#[IsGranted('ROLE_USER')]
class YouthDocumentController extends AbstractController
{
    public function __construct(
        private readonly YouthDocumentService $service,
        private readonly YouthDocumentRepository $documents,
        private readonly UserRepository $users,
        private readonly AvatarService $avatars,
        private readonly EntityManagerInterface $em,
        private readonly string $publicUrl,
    ) {
    }

    /** Vue d'ensemble pour le staff : les jeunes actifs et l'état de leurs deux documents. */
    #[Route('/api/staff/youth', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$this->isStaff($viewer) && !$viewer->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        $youth = $this->users->findActiveYouth();
        $grouped = $this->documents->findGroupedByOwner($youth);
        $today = new \DateTimeImmutable('today');

        return $this->noStore(new JsonResponse([
            'data' => array_map(fn (User $u) => $this->ownerItem($u) + [
                'documents' => $this->documentsMap($grouped[(int) $u->getId()] ?? [], $today),
            ], $youth),
            'total' => count($youth),
        ]));
    }

    /** Les documents d'un jeune (et, pour le staff, de quoi le joindre). */
    #[Route('/api/youth-documents/{userId}', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function show(int $userId): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $youth = $this->youthFor($userId, $viewer);
        if ($youth === null) {
            return $this->notFound();
        }

        return $this->noStore(new JsonResponse($this->detail($youth, $viewer)));
    }

    /**
     * Dépose (ou remplace) un document. Multipart : type (health_form | exit_authorization), file (PDF, JPG, PNG ou WebP,
     * 10 Mo max), validUntil (AAAA-MM-JJ, facultatif).
     */
    #[Route('/api/youth-documents/{userId}', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function upload(int $userId, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $youth = $this->youthFor($userId, $viewer);
        if ($youth === null) {
            return $this->notFound();
        }

        $type = YouthDocumentType::tryFrom((string) $request->request->get('type', ''));
        if ($type === null) {
            return $this->invalid('Type de document invalide.');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->invalid('Aucun fichier reçu.');
        }
        if (!$file->isValid()) {
            return $this->invalid('Le fichier n\'a pas pu être reçu (trop volumineux ?).');
        }
        if ((int) $file->getSize() > YouthDocumentService::MAX_BYTES) {
            return $this->invalid('Fichier trop volumineux (10 Mo max).');
        }
        $kind = YouthDocumentService::sniff($file->getPathname());
        if ($kind === null) {
            return $this->invalid('Format non accepté : envoyez un PDF ou une photo (JPG, PNG, WebP).');
        }

        $validUntil = null;
        $raw = trim((string) $request->request->get('validUntil', ''));
        if ($raw !== '') {
            $validUntil = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: null;
            if ($validUntil === null) {
                return $this->invalid('Date de fin de validité invalide (AAAA-MM-JJ).');
            }
        }

        try {
            $document = $this->service->store($youth, $type, $file, $kind, $viewer, $validUntil);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $this->em->flush();

        return new JsonResponse($this->item($document, new \DateTimeImmutable('today')), Response::HTTP_CREATED);
    }

    /** Supprime le document d'un type. */
    #[Route('/api/youth-documents/{userId}/{type}', methods: ['DELETE'], requirements: ['userId' => '\d+', 'type' => 'health_form|exit_authorization'])]
    public function delete(int $userId, string $type): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $youth = $this->youthFor($userId, $viewer);
        $document = $youth === null ? null : $this->documents->findOneByOwnerAndType($youth, YouthDocumentType::from($type));
        if ($youth === null || $document === null) {
            return $this->notFound('Document introuvable.');
        }

        $this->service->remove($document);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** Téléchargement authentifié (jeton en query `bearer`) : s'ouvre dans le navigateur (PDF, image). */
    #[Route('/api/youth-documents/file/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id): BinaryFileResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $document = $this->documents->find($id);
        $owner = $document?->getOwner();
        if ($document === null || $owner === null || !$owner->isJeune() || !$this->canAccess($viewer, $owner)) {
            throw $this->createNotFoundException();
        }
        $response = $this->service->fileResponse($document);
        if ($response === null) {
            throw $this->createNotFoundException();
        }

        return $response;
    }

    // ============================================================
    //  Aides
    // ============================================================

    private function isStaff(User $user): bool
    {
        return $user->isEntraineur() || $user->isEncadrant();
    }

    /** Le jeune lui-même, l'un de ses parents, le staff ou un administrateur. */
    private function canAccess(User $viewer, User $youth): bool
    {
        if ($viewer->getId() === $youth->getId() || $this->isStaff($viewer) || $viewer->isAdmin()) {
            return true;
        }
        foreach ($youth->getParents() as $parent) {
            if ($parent->getId() === $viewer->getId()) {
                return true;
            }
        }
        $primary = $youth->getLinkedToUser();

        return $primary !== null && $primary->getId() === $viewer->getId();
    }

    /** Le jeune demandé, s'il existe, a le profil Jeune et que `$viewer` y a accès. */
    private function youthFor(int $userId, User $viewer): ?User
    {
        $youth = $this->users->find($userId);

        return $youth !== null && $youth->isJeune() && $this->canAccess($viewer, $youth) ? $youth : null;
    }

    private function notFound(string $message = 'Jeune introuvable.'): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_NOT_FOUND);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Données de santé : jamais mises en cache par un navigateur ou un relais. */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array<string, mixed> */
    private function ownerItem(User $youth): array
    {
        $birth = $youth->getDateNaissance();

        return [
            'id' => $youth->getId(),
            'nom' => $youth->getNom(),
            'prenom' => $youth->getPrenom(),
            'avatarUrl' => $this->avatars->urlFor($youth),
            'age' => $birth === null ? null : $birth->diff(new \DateTimeImmutable('today'))->y,
        ];
    }

    /**
     * Les deux types de document d'un jeune, null quand il n'a rien déposé.
     *
     * @param array<string, YouthDocument> $byType
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function documentsMap(array $byType, \DateTimeImmutable $today): array
    {
        $out = [];
        foreach (YouthDocumentType::cases() as $type) {
            $document = $byType[$type->value] ?? null;
            $out[$type->value] = $document === null ? null : $this->item($document, $today);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function item(YouthDocument $document, \DateTimeImmutable $today): array
    {
        return [
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'typeLabel' => $document->getType()->label(),
            'name' => $document->getOriginalName(),
            'size' => $document->getSize(),
            'humanSize' => $document->getHumanSize(),
            'mimeType' => $document->getMimeType(),
            'uploadedAt' => $document->getUploadedAt()->format(\DATE_ATOM),
            'uploadedBy' => $document->getUploadedBy()?->getFullName(),
            'validUntil' => $document->getValidUntil()?->format('Y-m-d'),
            'expired' => $document->isExpired($today),
            'url' => rtrim($this->publicUrl, '/').'/api/youth-documents/file/'.$document->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(User $youth, User $viewer): array
    {
        $byType = $this->documents->findGroupedByOwner([$youth])[(int) $youth->getId()] ?? [];

        return [
            'owner' => $this->ownerItem($youth),
            'documents' => $this->documentsMap($byType, new \DateTimeImmutable('today')),
            // Le staff peut joindre le jeune (ou, à défaut, un parent) et son contact d'urgence depuis cette fiche.
            'contact' => $this->isStaff($viewer) || $viewer->isAdmin() ? $this->contact($youth) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function contact(User $youth): array
    {
        $phone = self::cleanPhone($youth->getTelephone());
        $phoneOf = null;
        if ($phone === null) {
            foreach ($youth->getParents() as $parent) {
                $parentPhone = self::cleanPhone($parent->getTelephone());
                if ($parentPhone !== null) {
                    $phone = $parentPhone;
                    $phoneOf = $parent->getFullName();
                    break;
                }
            }
        }

        return [
            'telephone' => $phone,
            'telephoneOf' => $phoneOf,
            'telephoneUrgence' => self::cleanPhone($youth->getTelephoneUrgence()),
        ];
    }

    private static function cleanPhone(?string $raw): ?string
    {
        $phone = $raw !== null ? trim($raw) : '';

        return $phone !== '' ? $phone : null;
    }
}
