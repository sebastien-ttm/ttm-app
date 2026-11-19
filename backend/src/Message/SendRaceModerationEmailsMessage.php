<?php

namespace App\Message;

/**
 * Message asynchrone : e-mails de la modération d'une compétition proposée (triathlon) — aux administrateurs quand une
 * proposition attend leur validation, à son auteur quand elle est validée ou refusée (avec le motif du refus).
 *
 * Le motif est figé dans le message : la proposition peut être modifiée (donc son motif effacé) avant que le worker ne
 * passe, mais l'auteur doit recevoir celui qui a été saisi à la décision.
 */
final readonly class SendRaceModerationEmailsMessage
{
    public const SUBMITTED = 'submitted';
    public const VALIDATED = 'validated';
    public const REFUSED = 'refused';

    public function __construct(
        public int $proposalId,
        public string $event,
        public ?string $note = null,
    ) {
    }
}
