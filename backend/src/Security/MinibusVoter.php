<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Accès au prêt du minibus du club.
 *
 *  - `MINIBUS` : l'emprunt dans l'appli (calendrier, demande de réservation, charte, états des lieux) — membres du CoDir
 *    seulement, pour le moment.
 *  - `MINIBUS_MANAGE` : la gestion (valider ou refuser les demandes, consulter tous les états des lieux, modifier la
 *    charte) — le gestionnaire du minibus (rôle complémentaire d'un membre du CoDir) et les administrateurs, dans le
 *    back-office ; dans l'appli, le gestionnaire du minibus (un administrateur sans rôle CoDir n'y entre pas).
 */
class MinibusVoter extends Voter
{
    public const ACCESS = 'MINIBUS';
    public const MANAGE = 'MINIBUS_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS || $attribute === self::MANAGE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::ACCESS => $user->getBoardRole() !== null,
            self::MANAGE => $user->isAdmin() || $user->isMinibusManager(),
            default => false,
        };
    }
}
