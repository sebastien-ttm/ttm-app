<?php

namespace App\EventListener;

use App\Entity\LoginEvent;
use App\Entity\User;
use App\Service\LoginRecorder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

/**
 * Met à jour lastLoginAt + loginCount + persiste un LoginEvent à
 * chaque connexion admin (form login web). La connexion mobile (JWT)
 * est gérée par AuthSuccessListener.
 */
class AdminLoginListener
{
    public function __construct(
        private readonly LoginRecorder $recorder,
    ) {
    }

    #[AsEventListener(event: InteractiveLoginEvent::class)]
    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if (!$user instanceof User) {
            return;
        }
        $this->recorder->record($user, LoginEvent::CHANNEL_ADMIN);
    }
}
