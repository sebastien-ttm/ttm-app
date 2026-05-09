<?php

namespace App\EventListener;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class AuthSuccessListener
{
    public function __construct(
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly int $refreshTtl = 2592000,
    ) {
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success')]
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $data = $event->getData();

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, $this->refreshTtl);
        $this->refreshTokenManager->save($refreshToken);

        $data['refresh_token'] = $refreshToken->getRefreshToken();
        $data['user'] = self::serializeUser($user);
        $event->setData($data);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'fullName' => $user->getFullName(),
            'numLicence' => $user->getNumLicence(),
            'categorie' => $user->getCategorie()->value,
            'roles' => $user->getRoles(),
            'hasPassword' => $user->getPassword() !== null,
        ];
    }
}
