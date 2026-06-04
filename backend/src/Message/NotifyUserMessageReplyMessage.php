<?php

namespace App\Message;

/**
 * Notifie l'expéditeur d'origine qu'une réponse à son message a été postée.
 */
final readonly class NotifyUserMessageReplyMessage
{
    public function __construct(public int $messageId)
    {
    }
}
