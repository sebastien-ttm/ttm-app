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

    public function buildWebUrl(string $token): string
    {
        return rtrim($this->publicUrl, '/').'/api/auth/magic-link/verify?token='.urlencode($token);
    }

    public function buildMobileUrl(string $token): string
    {
        return rtrim($this->mobileScheme, '/').'/auth/magic-link?token='.urlencode($token);
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
