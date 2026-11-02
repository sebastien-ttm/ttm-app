<?php

namespace App\Service\YouthDocument;

use App\Enum\YouthDocumentType;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Modèles des documents des jeunes déposés depuis le backend : un fichier par type, sous
 * var/uploads/youth-document-templates/{type}.{ext}. Quand il existe, il remplace le modèle par défaut (formulaire
 * Cerfa officiel, ou PDF du club généré par l'appli) — voir YouthDocumentController::template().
 */
class YouthDocumentTemplateService
{
    /** Taille maximale d'un modèle. */
    public const MAX_BYTES = 10_000_000;

    /** Extensions acceptées et leur type MIME. */
    private const MIME = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc' => 'application/msword',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function __construct(
        private readonly string $youthDocumentTemplatesDir,
    ) {
    }

    /**
     * Modèle déposé pour ce type, null s'il n'y en a pas (le modèle par défaut s'applique).
     *
     * @return array{path: string, ext: string, mime: string, name: string, size: int, updatedAt: \DateTimeImmutable}|null
     */
    public function find(YouthDocumentType $type): ?array
    {
        foreach (array_keys(self::MIME) as $ext) {
            $path = $this->path($type, $ext);
            if (is_file($path)) {
                return [
                    'path' => $path,
                    'ext' => $ext,
                    'mime' => self::MIME[$ext],
                    'name' => 'modele-'.str_replace('_', '-', $type->value).'.'.$ext,
                    'size' => (int) filesize($path),
                    'updatedAt' => (new \DateTimeImmutable())->setTimestamp((int) filemtime($path)),
                ];
            }
        }

        return null;
    }

    /**
     * Enregistre le fichier comme modèle de ce type (remplace le précédent).
     *
     * @throws \RuntimeException format refusé ou enregistrement impossible (message affichable)
     */
    public function store(YouthDocumentType $type, UploadedFile $file): void
    {
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException(sprintf('Fichier trop volumineux (%d Mo max).', (int) (self::MAX_BYTES / 1_000_000)));
        }
        $ext = self::detect($file);
        if ($ext === null) {
            throw new \RuntimeException('Format non accepté : PDF, Word (.docx, .doc), OpenDocument (.odt), JPG ou PNG.');
        }

        $dir = $this->youthDocumentTemplatesDir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier %s', $dir));
        }
        $this->remove($type);
        try {
            $file->move($dir, basename($this->path($type, $ext)));
        } catch (\Exception $e) {
            throw new \RuntimeException('Échec de l\'enregistrement du fichier : '.$e->getMessage(), 0, $e);
        }
    }

    /** Retire le modèle déposé : le modèle par défaut s'applique de nouveau. */
    public function remove(YouthDocumentType $type): void
    {
        foreach (array_keys(self::MIME) as $ext) {
            $path = $this->path($type, $ext);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Téléchargement du modèle déposé : PDF et images s'ouvrent dans le navigateur, les documents se téléchargent. */
    public function response(YouthDocumentType $type): ?BinaryFileResponse
    {
        $template = $this->find($type);
        if ($template === null) {
            return null;
        }
        $response = new BinaryFileResponse($template['path']);
        $inline = in_array($template['ext'], ['pdf', 'jpg', 'png'], true);
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $template['name']);
        $response->headers->set('Content-Type', $template['mime']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Extension retenue d'après le contenu du fichier (et son nom pour distinguer .docx de .odt, tous deux des ZIP). */
    private static function detect(UploadedFile $file): ?string
    {
        $head = (string) @file_get_contents($file->getPathname(), false, null, 0, 8);
        $name = strtolower($file->getClientOriginalExtension());
        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($head, "PK\x03\x04") && in_array($name, ['docx', 'odt'], true)) {
            return $name;
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0") && $name === 'doc') {
            return 'doc';
        }

        return null;
    }

    private function path(YouthDocumentType $type, string $ext): string
    {
        return rtrim($this->youthDocumentTemplatesDir, '/\\').\DIRECTORY_SEPARATOR.$type->value.'.'.$ext;
    }
}
