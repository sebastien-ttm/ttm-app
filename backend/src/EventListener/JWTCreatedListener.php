<?php

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

class JWTCreatedListener
{
    /**
     * Nom de l'attribut de requête utilisé par MeController::switchProfile
     * pour propager l'origine de session lors d'un switch de profil.
     */
    public const ORIGIN_ATTRIBUTE = '_jwt_origin_user_id';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        // Do NOT touch `sub`: Lexik sets it to the user identifier (email),
        // which the user provider needs to re-load the user on subsequent requests.
        $payload['uid'] = $user->getId();
        $payload['name'] = $user->getFullName();

        // Origine de session : si on est en plein switch de profil, l'appelant
        // a posé l'ID de l'origine sur la requête. Sinon on défaut sur soi-même
        // (= login initial ou refresh de token).
        $request = $this->requestStack->getCurrentRequest();
        $origin = $request?->attributes->get(self::ORIGIN_ATTRIBUTE);
        $payload['origin_user_id'] = is_int($origin) && $origin > 0 ? $origin : $user->getId();

        $event->setData($payload);
    }
}
