<?php

namespace App\Message;

/**
 * Prévient par e-mail l'interlocuteur d'un message posté dans une
 * discussion de la bourse aux équipements.
 */
final readonly class NotifyMarketplaceMessageMessage
{
    public function __construct(public int $messageId)
    {
    }
}
