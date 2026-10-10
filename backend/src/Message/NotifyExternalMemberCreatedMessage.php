<?php

namespace App\Message;

/**
 * Message asynchrone : prévenir les administrateurs qu'un compte « adhérent externe »
 * (licencié dans un autre club) vient d'être créé depuis l'appli, pour qu'ils l'activent
 * pour la saison en cours.
 */
final readonly class NotifyExternalMemberCreatedMessage
{
    public function __construct(public int $userId)
    {
    }
}
