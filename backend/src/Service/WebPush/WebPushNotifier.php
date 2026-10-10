<?php

namespace App\Service\WebPush;

use App\Message\SendWebPushMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Point d'entrée unique pour envoyer une notification push web à des
 * adhérents : l'envoi réel passe par la file Messenger (asynchrone), jamais
 * dans la requête de l'utilisateur. Sans clés VAPID, ne fait rien.
 */
class WebPushNotifier
{
    /** Longueur maximale du corps affiché (les navigateurs tronquent de toute façon). */
    private const MAX_BODY_LENGTH = 140;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly WebPushClient $client,
    ) {
    }

    /**
     * @param list<int>            $userIds
     * @param list<StampInterface> $stamps  ex : DelayStamp pour différer jusqu'à une date de publication
     */
    public function notifyUsers(array $userIds, string $title, string $body, string $url = '/', ?string $tag = null, array $stamps = []): void
    {
        if (!$this->client->isConfigured()) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return;
        }

        $this->bus->dispatch(new Envelope(
            new SendWebPushMessage($ids, $title, mb_strimwidth(trim($body), 0, self::MAX_BODY_LENGTH, '…'), $url, $tag),
            $stamps,
        ));
    }
}
