<?php

namespace App\MessageHandler;

use App\Message\SendWebPushMessage;
use App\Repository\WebPushSubscriptionRepository;
use App\Service\WebPush\WebPushClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendWebPushMessageHandler
{
    public function __construct(
        private readonly WebPushSubscriptionRepository $subscriptions,
        private readonly WebPushClient $client,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SendWebPushMessage $message): void
    {
        if (!$this->client->isConfigured()) {
            return;
        }
        $subscriptions = $this->subscriptions->findByUserIds($message->userIds);
        if ($subscriptions === []) {
            return;
        }

        $payload = (string) json_encode([
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
            'tag' => $message->tag,
            // « ?v= » : voir mobile/public/icons/README.md (cache des icônes).
            'icon' => '/icons/icon-192.png?v=2',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $results = $this->client->sendMany($subscriptions, $payload);

        foreach ($subscriptions as $subscription) {
            $result = $results[(int) $subscription->getId()] ?? null;
            if ($result === WebPushClient::RESULT_GONE) {
                // Abonnement révoqué ou expiré côté navigateur : on le retire.
                $this->em->remove($subscription);
            } elseif ($result === WebPushClient::RESULT_OK) {
                $subscription->touch();
            }
        }
        $this->em->flush();
    }
}
