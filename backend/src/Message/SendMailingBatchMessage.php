<?php

namespace App\Message;

/**
 * Message asynchrone : envoyer le prochain lot de destinataires d'un mailing.
 * Chaque lot en programme le suivant (avec un délai) ; runToken identifie la chaîne
 * d'envoi en cours — un message dont le jeton n'est plus celui du mailing est ignoré.
 */
final readonly class SendMailingBatchMessage
{
    public function __construct(public int $mailingId, public string $runToken)
    {
    }
}
