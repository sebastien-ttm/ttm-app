<?php

namespace App\Message;

/**
 * Notifie les destinataires (admins ou entraîneur ciblé) qu'un nouveau
 * message a été envoyé depuis l'app mobile.
 */
final readonly class NotifyNewUserMessageMessage
{
    public function __construct(public int $messageId)
    {
    }
}
