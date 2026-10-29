<?php

namespace App\Message;

/**
 * Prévient l'ANCIENNE et la NOUVELLE adresse qu'un changement d'e-mail
 * vient d'être confirmé (alerte de sécurité côté ancienne adresse).
 *
 * `detachedFrom` : nom du compte principal quand le compte qui change d'adresse était un profil lié à une adresse
 * partagée (il n'y est plus rattaché). `alsoChanged` : noms des profils rattachés qui changent d'adresse avec un compte
 * principal.
 */
final readonly class NotifyEmailChangedMessage
{
    /** @param list<string> $alsoChanged */
    public function __construct(
        public int $userId,
        public string $oldEmail,
        public string $newEmail,
        public ?string $detachedFrom = null,
        public array $alsoChanged = [],
    ) {
    }
}
