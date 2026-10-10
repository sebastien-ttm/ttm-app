<?php

namespace App\Service\Mailing;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Liens de désinscription des mailings, sans stockage : la page est protégée par
 * une signature HMAC de l'identifiant de l'adhérent (clé = APP_SECRET), donc
 * impossible à deviner ou à fabriquer pour désinscrire quelqu'un d'autre.
 *
 * L'adresse est sous /api/public/ : c'est le seul préfixe servi par Symfony et
 * accessible sans connexion (le reste du site est l'appli mobile).
 */
final class MailingUnsubscribeLinks
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly string $publicUrl,
    ) {
    }

    public function signature(int $userId): string
    {
        return substr(hash_hmac('sha256', 'mailing-unsubscribe:'.$userId, $this->secret), 0, 40);
    }

    public function isValid(int $userId, string $signature): bool
    {
        return hash_equals($this->signature($userId), $signature);
    }

    public function urlFor(User $user): string
    {
        $id = (int) $user->getId();

        return rtrim($this->publicUrl, '/').'/api/public/mailing/unsubscribe/'.$id.'/'.$this->signature($id);
    }
}
