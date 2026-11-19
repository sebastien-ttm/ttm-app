<?php

namespace App\Service\Board;

use App\Entity\User;
use App\Enum\BoardRole;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Signature du compte rendu des réunions du CoDir : celle de la personne qui a le rôle CODIR « Secrétaire », insérée
 * automatiquement au bas du compte rendu.
 *
 * La signature manuscrite (image PNG / JPG / WebP) se paramètre sur la fiche du compte dans le back-office
 * (Utilisateurs > Modifier) et suit la personne : quand un autre adhérent devient Secrétaire, sa signature remplace
 * celle de l'ancien sans autre réglage. Le fichier est stocké hors de la partie publique du site
 * (var/uploads/user-signatures/) et n'est jamais servi par une adresse : le compte rendu l'intègre directement.
 */
class MinutesSignatureService
{
    public const MAX_BYTES = 2_000_000;

    /** Types d'image acceptés (type MIME détecté sur le contenu → extension). Pas de SVG : il peut contenir du script. */
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly string $signatureDir,
    ) {
    }

    /**
     * Les comptes actifs qui ont le rôle Secrétaire (normalement un seul) avec leur signature en « data URI » (null si
     * elle n'est pas renseignée : le compte rendu laisse alors la place de signer à la main).
     *
     * @return list<array{name: string, role: string, signature: ?string, hasSignature: bool, userId: ?int}>
     */
    public function secretaries(): array
    {
        $out = [];
        foreach ($this->users->findCommitteeMembers() as $member) {
            if ($member->getBoardRole() !== BoardRole::Secretaire) {
                continue;
            }
            $signature = $this->dataUri($member);
            $out[] = [
                'name' => $member->getFullName(),
                'role' => BoardRole::Secretaire->label(),
                'signature' => $signature,
                'hasSignature' => $signature !== null,
                'userId' => $member->getId(),
            ];
        }

        return $out;
    }

    /**
     * Enregistre la signature envoyée pour ce compte (remplace la précédente). Retourne un message d'erreur montrable
     * à l'administrateur, ou null si tout s'est bien passé.
     */
    public function store(User $user, UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return 'Signature : l\'envoi du fichier a échoué, réessayez.';
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return 'Signature trop lourde (2 Mo au plus).';
        }
        // Type d'après le contenu du fichier, pas d'après son nom ni le type annoncé par le navigateur.
        $extension = self::TYPES[(string) $file->getMimeType()] ?? null;
        if ($extension === null || @getimagesize($file->getPathname()) === false) {
            return 'Signature : format non accepté (PNG, JPG ou WebP).';
        }
        if (!is_dir($this->signatureDir) && !@mkdir($this->signatureDir, 0775, true) && !is_dir($this->signatureDir)) {
            return 'Signature : impossible de créer le dossier de destination.';
        }

        $name = 'signature-'.bin2hex(random_bytes(8)).'.'.$extension;
        try {
            $file->move($this->signatureDir, $name);
        } catch (\Throwable $e) {
            return 'Signature : l\'enregistrement du fichier a échoué ('.$e->getMessage().').';
        }
        $this->removeFile($user->getSignatureFilename());
        $user->setSignatureFilename($name);

        return null;
    }

    /** Supprime la signature du compte (fichier et référence). */
    public function delete(User $user): void
    {
        $this->removeFile($user->getSignatureFilename());
        $user->setSignatureFilename(null);
    }

    /** Signature du compte sous forme de « data URI » intégrable dans une page, ou null si elle n'existe pas / est illisible. */
    public function dataUri(User $user): ?string
    {
        $filename = $user->getSignatureFilename();
        if ($filename === null) {
            return null;
        }
        $path = $this->pathOf($filename);
        if (!is_file($path)) {
            return null;
        }
        // Les fichiers sont enregistrés avec l'extension de leur type détecté (voir store()) : on la retraduit en type MIME.
        $mime = array_search(strtolower(pathinfo($filename, \PATHINFO_EXTENSION)), self::TYPES, true);
        $content = @file_get_contents($path);
        if ($content === false || !is_string($mime)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }

    private function removeFile(?string $filename): void
    {
        if ($filename !== null) {
            @unlink($this->pathOf($filename));
        }
    }

    /** Chemin du fichier : le nom est ramené à sa dernière composante, jamais de sortie du dossier. */
    private function pathOf(string $filename): string
    {
        return rtrim($this->signatureDir, '/\\').\DIRECTORY_SEPARATOR.basename($filename);
    }
}
