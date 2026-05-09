<?php

namespace App\Message;

final readonly class SendPushNotificationsMessage
{
    /**
     * @param list<string> $expoTokens
     * @param array<string, mixed> $data
     */
    public function __construct(
        public array $expoTokens,
        public string $title,
        public string $body,
        public array $data = [],
    ) {
    }
}
