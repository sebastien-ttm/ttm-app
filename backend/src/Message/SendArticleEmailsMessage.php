<?php

namespace App\Message;

/**
 * Message asynchrone : envoyer un email à tous les destinataires
 * éligibles (UserRepository::findArticleEmailRecipients) pour annoncer
 * la publication d'un nouvel article. Miroir de SendTrainingPlanEmails.
 */
final readonly class SendArticleEmailsMessage
{
    public function __construct(public int $articleId)
    {
    }
}
