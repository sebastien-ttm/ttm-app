<?php

namespace App\Message;

/**
 * Envoie le lien de confirmation d'un changement d'e-mail à l'adresse
 * ACTUELLE du compte. Le jeton en clair transite dans le message
 * (comme SendMagicLinkEmailMessage) — la base ne stocke que son hash.
 */
final readonly class SendEmailChangeConfirmationMessage
{
    public function __construct(
        public int $requestId,
        public string $clearToken,
    ) {
    }
}
