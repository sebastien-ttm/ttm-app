<?php

namespace App\Message;

/**
 * Message asynchrone : envoyer à un gestionnaire externe l'e-mail d'accès (lien pour créer son mot de passe,
 * boutons vers l'application et vers le backend). Le lien est émis au moment de l'envoi, pas ici : le message ne
 * transporte aucun jeton.
 */
final readonly class SendGestionnaireWelcomeEmailMessage
{
    public function __construct(public int $userId)
    {
    }
}
