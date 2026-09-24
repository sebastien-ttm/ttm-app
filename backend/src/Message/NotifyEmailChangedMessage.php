<?php

namespace App\Message;

/**
 * Prévient l'ANCIENNE et la NOUVELLE adresse qu'un changement d'e-mail
 * vient d'être confirmé (alerte de sécurité côté ancienne adresse).
 */
final readonly class NotifyEmailChangedMessage
{
    public function __construct(
        public int $userId,
        public string $oldEmail,
        public string $newEmail,
    ) {
    }
}
