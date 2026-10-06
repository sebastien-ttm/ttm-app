<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attribut `CAP_DISTRIBUTION` (voir CapDistributionController) : ouvre
 * l'émargement des bonnets du club aux entraîneurs, aux admins et aux
 * membres du CoDir (qui n'ont sinon que ROLE_EDITEUR).
 */
class CapDistributionVoter extends Voter
{
    public const ATTRIBUTE = 'CAP_DISTRIBUTION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user instanceof User
            && ($user->isAdmin() || $user->isEntraineurAdmin() || $user->getBoardRole() !== null);
    }
}
