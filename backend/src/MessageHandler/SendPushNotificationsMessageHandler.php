<?php

namespace App\MessageHandler;

use App\Message\SendPushNotificationsMessage;
use App\Service\ExpoPushClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendPushNotificationsMessageHandler
{
    public function __construct(private readonly ExpoPushClient $expo)
    {
    }

    public function __invoke(SendPushNotificationsMessage $message): void
    {
        $this->expo->send(
            tokens: $message->expoTokens,
            title: $message->title,
            body: $message->body,
            data: $message->data,
        );
    }
}
