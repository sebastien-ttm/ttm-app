<?php

namespace App\Message;

final readonly class SendMagicLinkEmailMessage
{
    public function __construct(
        public int $userId,
        public string $clearToken,
        public bool $isWelcome = false,
        /** Deep-link cible à ouvrir après auth (ex: /page/entrainements). */
        public ?string $next = null,
    ) {
    }
}
