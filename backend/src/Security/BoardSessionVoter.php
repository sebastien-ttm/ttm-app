<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Accès à l'ordre du jour du comité directeur.
 *
 *  - `BOARD_SESSION` : participer — ajout de points et commentaires dans l'appli, page « En séance » du back-office
 *    (réponses, décisions, porteurs d'action). Membres du CoDir (tout rôle CoDir) et administrateurs.
 *  - `BOARD_VIEW` : consulter — réunions, ordre du jour, décisions et actions dans l'appli (onglet Social). Les
 *    mêmes, plus les entraîneurs, invités aux réunions mais qui ne participent pas à l'ordre du jour.
 *
 * La création / suppression des réunions reste réservée aux administrateurs (ROLE_ADMIN).
 */
class BoardSessionVoter extends Voter
{
    public const ATTRIBUTE = 'BOARD_SESSION';
    public const VIEW = 'BOARD_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE || $attribute === self::VIEW;
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

        return $attribute === self::VIEW && $user->isEntraineur();
    }
}
