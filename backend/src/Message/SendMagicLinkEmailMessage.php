<?php

namespace App\Message;

final readonly class SendMagicLinkEmailMessage
{
    public function __construct(
        public int $userId,
        public string $clearToken,
        public bool $isWelcome = false,
    ) {
    }
}
