<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Force les pages backend EasyAdmin à ne JAMAIS être mises en cache
 * (navigateur ou proxy intermédiaire). Évite le symptôme classique
 * « j'ai modifié un événement mais la liste affiche encore l'ancienne
 * valeur tant que je n'appuie pas sur F5 ».
 *
 * Limité aux requêtes /admin* — les routes /api et les assets publics
 * gardent leur comportement de cache normal.
 */
class AdminNoCacheListener
{
    #[AsEventListener(event: ResponseEvent::class)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/admin')) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }
}
