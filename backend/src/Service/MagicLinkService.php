<?php

namespace App\Service;

use App\Entity\MagicLinkToken;
use App\Entity\User;
use App\Repository\MagicLinkTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class MagicLinkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagicLinkTokenRepository $tokens,
        private readonly string $publicUrl,
        private readonly string $mobileScheme,
        private readonly int $ttl,
    ) {
    }

    /**
     * @return array{token: string, entity: MagicLinkToken}
     */
    public function issue(User $user): array
    {
        $clear = bin2hex(random_bytes(32));
        $hash = hash('sha256', $clear);
        $expires = new \DateTimeImmutable('+'.$this->ttl.' seconds');

        $entity = new MagicLinkToken($user, $hash, $expires);
        $this->em->persist($entity);
        $this->em->flush();

        return ['token' => $clear, 'entity' => $entity];
    }

    /**
     * URL universelle envoyée par e-mail. Pointe vers la route SPA mobile
     * `/auth/magic-link?token=...` :
     *  - Sur web (browser) → l'app Expo charge, consomme le token, connecte
     *  - Sur device avec une app native installée et un Universal/App Link
     *    configuré sur ce domaine → ouvre l'app directement
     *
     * On évite volontairement les schemes custom (ttm://...) car la plupart
     * des clients mail (Gmail en tête) refusent de les rendre cliquables.
     */
    public function buildWebUrl(string $token): string
    {
        return rtrim($this->publicUrl, '/').'/auth/magic-link?token='.urlencode($token);
    }

    /**
     * Alias rétro-compatible — même URL que buildWebUrl (les Universal Links
     * sont des URLs HTTPS classiques, pas des schemes custom).
     */
    public function buildMobileUrl(string $token): string
    {
        return $this->buildWebUrl($token);
    }

    public function consume(string $clearToken): ?User
    {
        $hash = hash('sha256', $clearToken);
        $entity = $this->tokens->findOneByTokenHash($hash);
        if ($entity === null || !$entity->isUsable()) {
            return null;
        }
        $entity->markUsed();
        $this->em->flush();

        return $entity->getUser();
    }
}
