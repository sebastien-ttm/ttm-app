<?php

namespace App\Service\Board;

use App\Entity\BoardMeeting;
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
 *
 * À la clôture de la réunion, le signataire est figé (nom, rôle, copie de la signature : `meeting-<id>-….<ext>` dans le
 * même dossier) : un compte rendu déjà clos ne change plus si le Secrétaire change ensuite. Rouvrir la réunion défige ;
 * la clore à nouveau fige le Secrétaire alors en fonction.
 */
class MinutesSignatureService
{
    public const MAX_BYTES = 2_000_000;

    /** Types d'image acceptés (type MIME détecté sur le contenu → extension). Pas de SVG : il peut contenir du script. */
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** Préfixe des copies de signature figées à la clôture d'une réunion (les signatures de compte sont « signature-… »). */
    private const SNAPSHOT_PREFIX = 'meeting-';

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
        foreach ($this->secretaryUsers() as $member) {
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
     * Signataire(s) à afficher sur le compte rendu de cette réunion : ceux figés à sa clôture s'il y en a (le compte rendu
     * ne bouge plus si le Secrétaire change ensuite), sinon les Secrétaires actuels (réunion encore ouverte).
     *
     * @return list<array{name: string, role: string, signature: ?string, hasSignature: bool, userId: ?int}>
     */
    public function signersFor(BoardMeeting $meeting): array
    {
        $frozen = $meeting->getMinutesSigners();
        if ($frozen === null) {
            return $this->secretaries();
        }
        $out = [];
        foreach ($frozen as $signer) {
            $signature = $this->dataUriOfFile($signer['file'] ?? null);
            $out[] = [
                'name' => (string) ($signer['name'] ?? ''),
                'role' => (string) ($signer['role'] ?? BoardRole::Secretaire->label()),
                'signature' => $signature,
                'hasSignature' => $signature !== null,
                'userId' => null,
            ];
        }

        return $out;
    }

    /**
     * Fige le signataire d'une réunion qui vient d'être close : nom, rôle et COPIE de la signature du Secrétaire en
     * fonction (la signature du compte peut ensuite être remplacée ou le rôle changer de main sans toucher au compte
     * rendu). Remplace un éventuel instantané précédent. À appeler avant le flush de l'entité.
     */
    public function freeze(BoardMeeting $meeting): void
    {
        $this->release($meeting);
        $signers = [];
        foreach ($this->secretaryUsers() as $member) {
            $signers[] = [
                'name' => $member->getFullName(),
                'role' => BoardRole::Secretaire->label(),
                'file' => $this->copySnapshot($meeting, $member->getSignatureFilename()),
            ];
        }
        $meeting->setMinutesSigners($signers);
    }

    /** Défige : supprime les copies de signature de la réunion (rouverte ou supprimée) et efface l'instantané. */
    public function release(BoardMeeting $meeting): void
    {
        foreach ($meeting->getMinutesSigners() ?? [] as $signer) {
            $file = $signer['file'] ?? null;
            // Seules les copies propres à une réunion sont supprimées, jamais le fichier d'un compte (signature-*).
            if (is_string($file) && str_starts_with(basename($file), self::SNAPSHOT_PREFIX)) {
                $this->removeFile($file);
            }
        }
        $meeting->setMinutesSigners(null);
    }

    /**
     * Applique la (ré)ouverture / clôture d'une réunion : fige le signataire quand elle passe de ouverte à close, le
     * défige quand elle est rouverte. Ne fait rien si l'état n'a pas changé. À appeler avant le flush de l'entité.
     */
    public function syncWithClosure(BoardMeeting $meeting, bool $wasClosed): void
    {
        if (!$wasClosed && $meeting->isClosed()) {
            $this->freeze($meeting);
        } elseif ($wasClosed && !$meeting->isClosed()) {
            $this->release($meeting);
        }
    }

    /**
     * Réunion close avant la mise en service du figeage (aucun instantané) : on fige le Secrétaire en fonction, ce qui
     * empêche toute dérive à partir de maintenant. Retourne true si l'entité a changé (à enregistrer par l'appelant).
     */
    public function freezeIfMissing(BoardMeeting $meeting): bool
    {
        if (!$meeting->isClosed() || $meeting->hasFrozenSigners()) {
            return false;
        }
        $this->freeze($meeting);

        return true;
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
        return $this->dataUriOfFile($user->getSignatureFilename());
    }

    /** « data URI » d'un fichier du dossier des signatures (signature de compte ou copie figée), null s'il n'existe pas. */
    private function dataUriOfFile(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
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

    /** Comptes actifs qui ont le rôle Secrétaire (normalement un seul). @return list<User> */
    private function secretaryUsers(): array
    {
        return array_values(array_filter(
            $this->users->findCommitteeMembers(),
            static fn (User $member) => $member->getBoardRole() === BoardRole::Secretaire,
        ));
    }

    /** Copie la signature d'un compte pour la réunion (nom propre à la réunion) ; null si le compte n'en a pas / copie impossible. */
    private function copySnapshot(BoardMeeting $meeting, ?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }
        $source = $this->pathOf($filename);
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if (!is_file($source) || !in_array($extension, self::TYPES, true)) {
            return null;
        }
        $name = self::SNAPSHOT_PREFIX.(int) $meeting->getId().'-'.bin2hex(random_bytes(6)).'.'.$extension;

        return @copy($source, $this->pathOf($name)) ? $name : null;
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
