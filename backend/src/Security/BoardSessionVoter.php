<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attribut `BOARD_SESSION` : ordre du jour du comité directeur — ajout de points dans l'appli et page « En séance »
 * du back-office (réponses, décisions, porteurs d'action). Ouvert aux membres du CoDir (tout rôle CoDir) et aux
 * administrateurs. La création / suppression des réunions reste réservée aux administrateurs (ROLE_ADMIN).
 */
class BoardSessionVoter extends Voter
{
    public const ATTRIBUTE = 'BOARD_SESSION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user instanceof User && ($user->isAdmin() || $user->getBoardRole() !== null);
    }
}
