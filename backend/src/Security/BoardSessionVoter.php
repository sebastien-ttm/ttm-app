<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Accès au comité directeur.
 *
 *  - `BOARD_AGENDA` : l'ordre du jour dans l'appli (onglet Social) — consulter les réunions, ajouter des points,
 *    commenter, lire les décisions et les actions. Membres du CoDir (tout rôle CoDir), administrateurs, et
 *    entraîneurs, invités aux réunions.
 *  - `BOARD_SESSION` : la page « En séance » du back-office (réponses, décisions, porteurs d'action saisis pendant
 *    la réunion). Membres du CoDir et administrateurs seulement.
 *
 * La création / suppression des réunions reste réservée aux administrateurs (ROLE_ADMIN).
 */
class BoardSessionVoter extends Voter
{
    public const ATTRIBUTE = 'BOARD_SESSION';
    public const AGENDA = 'BOARD_AGENDA';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE || $attribute === self::AGENDA;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($user->isAdmin() || $user->getBoardRole() !== null) {
            return true;
        }

        return $attribute === self::AGENDA && $user->isEntraineur();
    }
}
