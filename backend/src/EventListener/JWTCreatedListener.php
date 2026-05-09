<?php

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class JWTCreatedListener
{
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
        $event->setData($payload);
    }
}
