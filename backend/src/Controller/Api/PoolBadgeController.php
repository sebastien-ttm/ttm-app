<?php

namespace App\Controller\Api;

use App\Repository\PoolBadgeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class PoolBadgeController extends AbstractController
{
    public function __construct(
        private readonly PoolBadgeRepository $badges,
        private readonly string $publicUrl,
    ) {
    }

    /**
     * GET /api/pool-badge — retourne le badge piscines courant si défini.
     */
    #[Route('/api/pool-badge', methods: ['GET'])]
    public function current(): JsonResponse
    {
        $badge = $this->badges->findCurrent();
        if ($badge === null || !$badge->hasImage()) {
            return new JsonResponse(['data' => null]);
        }
        // Fallback : si mime_type n'a pas été enregistré (ancien fichier),
        // on devine via l'extension du nom de fichier.
        $mime = $badge->getMimeType()
            ?? $this->guessMimeFromFilename($badge->getImagePath());

        return new JsonResponse(['data' => [
            'id' => $badge->getId(),
            'title' => $badge->getTitle(),
            'notes' => $badge->getNotes(),
            'imageUrl' => rtrim($this->publicUrl, '/').'/uploads/pool-badges/'.$badge->getImagePath(),
            'mimeType' => $mime,
            'isPdf' => $mime === 'application/pdf',
            'updatedAt' => $badge->getUpdatedAt()?->format(\DATE_ATOM),
        ]]);
    }

    private function guessMimeFromFilename(?string $name): ?string
    {
        if ($name === null) return null;
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
    }
}
