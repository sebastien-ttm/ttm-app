<?php

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Corrige `$this->getUser()` sur les endpoints /api quand plusieurs
 * comptes partagent un email.
 *
 * Le firewall API (lexik JWT) charge le user via son claim `sub` = email
 * → le UserProvider entity.email retourne le PREMIER user avec cet
 * email, pas forcément celui identifié dans le JWT courant (après un
 * /switch-profile, ou lors de connexion sur un compte lié). Ce listener
 * lit le claim `uid` (posé par JWTCreatedListener) et remplace le user
 * du token de sécurité par le bon avant que le contrôleur ne s'exécute.
 *
 * S'exécute uniquement sur /api/* pour ne pas perturber le firewall admin.
 */
class JwtUidResolverListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly UserRepository $users,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: 100)]
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!str_starts_with((string) $request->getPathInfo(), '/api/')) {
            return;
        }
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }
        $current = $token->getUser();
        if (!$current instanceof User) {
            return;
        }

        // Extrait le JWT brut : Authorization: Bearer <token> OU ?bearer=<token>
        $raw = null;
        $auth = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            $raw = substr($auth, 7);
        } elseif ($request->query->has('bearer')) {
            $raw = (string) $request->query->get('bearer');
        }
        if ($raw === null || $raw === '') {
            return;
        }

        try {
            $payload = $this->jwt->parse($raw);
        } catch (\Throwable) {
            return;
        }

        $uid = $payload['uid'] ?? null;
        if (!is_int($uid) || $uid <= 0) {
            return;
        }
        if ($uid === $current->getId()) {
            return;
        }

        $resolved = $this->users->find($uid);
        if (!$resolved instanceof User) {
            return;
        }

        // Modifie l'utilisateur du token existant plutôt que le remplacer.
        // Préserve la classe concrète (JWTPostAuthenticationToken pour Lexik)
        // ET tout autre attribut interne : sub-type, attributs, credentials
        // (le JWT brut nécessaire à certains listeners aval). Une simple
        // reconstruction du token perdait ces éléments et cassait l'auth
        // en aval → 401.
        if (method_exists($token, 'setUser')) {
            $token->setUser($resolved);
        }
    }
}
