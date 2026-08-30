<?php

namespace App\Message;

final readonly class SendInvoiceEmailMessage
{
    public function __construct(
        public int $userId,
        public int $seasonId,
    ) {
    }
}
