<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

/**
 * Met à jour lastLoginAt + loginCount à chaque connexion admin
 * (form login web). La connexion mobile (JWT) est gérée par
 * AuthSuccessListener.
 */
class AdminLoginListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: InteractiveLoginEvent::class)]
    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if (!$user instanceof User) {
            return;
        }
        $user->recordLogin();
        $this->em->flush();
    }
}
