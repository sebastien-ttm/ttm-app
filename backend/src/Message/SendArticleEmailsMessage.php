<?php

namespace App\Message;

/**
 * Message asynchrone : notifier (push web) la publication d'un nouvel article. Le nom est un héritage : il n'y a
 * plus d'e-mail d'article, seuls les plans d'entraînement partent par e-mail.
 */
final readonly class SendArticleEmailsMessage
{
    public function __construct(public int $articleId)
    {
    }
}
