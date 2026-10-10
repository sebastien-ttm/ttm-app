<?php

namespace App\Message;

/**
 * Message asynchrone : envoyer à l'adhérent le lien de réinitialisation de son mot de passe.
 */
final readonly class SendPasswordResetEmailMessage
{
    public function __construct(public int $userId, public string $clearToken)
    {
    }
}
