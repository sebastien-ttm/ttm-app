<?php

namespace App\Controller\Admin;

use App\Service\ImageResizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Handles inline image uploads from rich-text editors (Trix in EasyAdmin).
 *
 * Files land in /public/uploads/inline/YYYY-MM/ and are publicly servable.
 * The URL is returned as JSON for the editor to set on the attachment.
 */
class InlineUploadController extends AbstractController
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_BYTES = 5_000_000; // 5 MB

    public function __construct(
        private readonly string $uploadDir,
        private readonly SluggerInterface $slugger,
        private readonly ImageResizer $resizer,
    ) {
    }

    #[Route('/admin/upload/inline', name: 'admin_inline_upload', methods: ['POST'])]
    #[IsGranted('ROLE_COACH')]
    public function upload(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return new JsonResponse(['error' => 'Aucun fichier reçu.'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'Fichier trop volumineux (max 5 Mo).'], Response::HTTP_BAD_REQUEST);
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return new JsonResponse(
                ['error' => 'Format non accepté. Formats autorisés : JPG, PNG, WebP, GIF.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $subdir = date('Y-m');
        $targetDir = rtrim($this->uploadDir, '/\\').\DIRECTORY_SEPARATOR.$subdir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return new JsonResponse(['error' => 'Impossible de créer le dossier.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safe = $this->slugger->slug($original)->lower()->toString();
        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension();
        $filename = sprintf('%s-%s.%s', $safe ?: 'image', bin2hex(random_bytes(4)), $ext);

        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Échec du déplacement : '.$e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $absolutePath = $targetDir.\DIRECTORY_SEPARATOR.$filename;

        // Auto-resize : on plafonne à 1200px de large (préserve le ratio).
        // Permet à l'admin de surcharger via ?max=800 par exemple.
        $maxWidth = max(200, min(2400, (int) ($request->query->get('max') ?: 1200)));
        $this->resizer->resizeInPlace($absolutePath, $mime, $maxWidth);

        $publicUrl = sprintf('/uploads/inline/%s/%s', $subdir, $filename);

        return new JsonResponse([
            'url' => $publicUrl,
            'href' => $publicUrl,
            'filename' => $filename,
            'size' => filesize($absolutePath),
            'mime' => $mime,
        ]);
    }
}
