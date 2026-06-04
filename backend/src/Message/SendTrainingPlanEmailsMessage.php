<?php

namespace App\Message;

/**
 * Message asynchrone : envoyer un email à tous les destinataires éligibles
 * (UserRepository::findTrainingPlanEmailRecipients) pour annoncer la
 * publication d'un nouveau plan d'entraînement.
 */
final readonly class SendTrainingPlanEmailsMessage
{
    public function __construct(public int $planId)
    {
    }
}
