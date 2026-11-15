<?php

namespace App\Service\WebPush;

use App\Entity\User;
use App\Enum\PushTopic;
use App\Message\SendWebPushMessage;
use App\Repository\WebPushSubscriptionRepository;
use App\Service\Audience\AudienceFilter;
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
        private readonly WebPushSubscriptionRepository $subscriptions,
        private readonly AudienceFilter $audienceFilter,
    ) {
    }

    /**
     * Notifie un SUJET (PushTopic) à tous les adhérents abonnés qui ne l'ont pas désactivé, et qui peuvent
     * voir le contenu : mêmes règles d'audience que l'appli (AudienceFilter).
     *
     * @param list<string>           $audience         profils visés par le contenu (vide = tout le monde)
     * @param list<string>           $contentAudience  tags de contenu (un Dirigeant ne voit que « école de triathlon » ou sans tag)
     * @param User|null              $exclude          l'auteur du contenu : jamais prévenu de sa propre publication
     * @param (\Closure(User): bool)|null $recipientFilter règle supplémentaire (participants d'un stage, accès à la bourse…)
     * @param list<StampInterface>   $stamps           ex : DelayStamp pour différer jusqu'à une date de publication
     */
    public function notifyTopic(
        PushTopic $topic,
        string $title,
        string $body,
        string $url = '/',
        ?string $tag = null,
        array $audience = [],
        array $contentAudience = [],
        ?User $exclude = null,
        ?\Closure $recipientFilter = null,
        array $stamps = [],
    ): void {
        if (!$this->client->isConfigured()) {
            return;
        }

        $ids = [];
        foreach ($this->subscriptions->findSubscribedUsers() as $user) {
            if ($exclude !== null && $user->getId() === $exclude->getId()) {
                continue;
            }
            if (!$user->isPushTopicEnabled($topic)
                || !$this->audienceFilter->isVisible($audience, $user)
                || !$this->audienceFilter->isContentVisibleForDirigeant($contentAudience, $user)
                || ($recipientFilter !== null && !$recipientFilter($user))) {
                continue;
            }
            $ids[] = (int) $user->getId();
        }

        $this->notifyUsers($ids, $title, $body, $url, $tag, $stamps, $topic);
    }

    /**
     * Règle « participants seulement » pour le paramètre recipientFilter de notifyTopic() (contenu dédié à un
     * stage ou à un déplacement).
     *
     * @param iterable<User> $users
     * @return \Closure(User): bool
     */
    public static function onlyThese(iterable $users): \Closure
    {
        $ids = [];
        foreach ($users as $u) {
            $ids[(int) $u->getId()] = true;
        }

        return static fn (User $user): bool => isset($ids[(int) $user->getId()]);
    }

    /**
     * @param list<int>            $userIds
     * @param list<StampInterface> $stamps  ex : DelayStamp pour différer jusqu'à une date de publication
     * @param PushTopic|null       $topic   si renseigné, les adhérents qui l'ont désactivé sont écartés à l'envoi
     */
    public function notifyUsers(array $userIds, string $title, string $body, string $url = '/', ?string $tag = null, array $stamps = [], ?PushTopic $topic = null): void
    {
        if (!$this->client->isConfigured()) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return;
        }

        $this->bus->dispatch(new Envelope(
            new SendWebPushMessage($ids, $title, mb_strimwidth(trim($body), 0, self::MAX_BODY_LENGTH, '…'), $url, $tag, $topic?->value),
            $stamps,
        ));
    }
}
