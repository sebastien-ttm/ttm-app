<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (!$user->isActive()) {
            // Ce texte est reconnu par l'appli mobile (mobile/src/api/client.ts, ACCOUNT_DISABLED_PATTERN : « compte a été
            // désactivé ») pour afficher le message de réinscription au lieu de « mot de passe incorrect » : le modifier
            // impose d'adapter aussi ce motif. Une exception « CustomUserMessage… » est la seule dont Symfony laisse
            // passer le message (les autres deviennent « Bad credentials »).
            throw new CustomUserMessageAccountStatusException('Votre compte a été désactivé.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
