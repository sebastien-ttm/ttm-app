<?php

namespace App\Message;

/**
 * Message asynchrone : créer l'événement Google Agenda d'une réunion du CoDir et envoyer l'invitation (adresse générique
 * du CoDir, ou chaque membre / entraîneur : voir le handler). `replace` : la réunion a déjà un événement, on le supprime (annulation envoyée aux invités) et on en
 * crée un nouveau.
 */
final readonly class CreateBoardMeetingCalendarEventMessage
{
    public function __construct(public int $meetingId, public bool $replace = false)
    {
    }
}
