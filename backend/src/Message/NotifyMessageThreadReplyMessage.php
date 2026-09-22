<?php

namespace App\Message;

/**
 * Message asynchrone : un nouveau tour a été posté dans une
 * conversation déjà ouverte (au-delà du 2e échange verrouillé) —
 * notifie par email l'autre partie (expéditeur si l'auteur du tour
 * est côté destinataire, ou inversement).
 */
final readonly class NotifyMessageThreadReplyMessage
{
    public function __construct(public int $replyId)
    {
    }
}
