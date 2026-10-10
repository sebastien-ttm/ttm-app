<?php

namespace App\Message;

/**
 * Notification push web à envoyer à tous les appareils abonnés de ces
 * adhérents. `url` est le chemin ouvert au clic (ex : « /event/12 ») ;
 * `tag` regroupe/remplace les notifications d'un même sujet.
 */
final readonly class SendWebPushMessage
{
    /**
     * @param list<int> $userIds
     */
    public function __construct(
        public array $userIds,
        public string $title,
        public string $body,
        public string $url = '/',
        public ?string $tag = null,
    ) {
    }
}
