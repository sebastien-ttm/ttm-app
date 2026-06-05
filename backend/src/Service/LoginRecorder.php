<?php

namespace App\Service;

use App\Entity\LoginEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralise l'enregistrement d'une connexion réussie :
 *  - met à jour User.lastLoginAt + loginCount (rétrocompat affichage CRUD)
 *  - persiste un LoginEvent (historique fin pour stats)
 *
 * Appelé depuis AdminLoginListener (web form) et AuthSuccessListener (mobile JWT).
 */
class LoginRecorder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function record(User $user, string $channel): void
    {
        $now = new \DateTimeImmutable();
        $user->recordLogin($now);
        $this->em->persist(new LoginEvent($user, $channel, $now));
        $this->em->flush();
    }
}
