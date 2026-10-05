<?php

namespace App\Security;

use App\Entity\OwnedContentInterface;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attribut `CONTENT_DELETE` (posé sur l'action Supprimer des CRUD
 * sondages, articles, calendrier et pages statiques) : un simple
 * éditeur ne peut supprimer que les contenus dont il est le créateur ;
 * les entraîneurs et les admins peuvent tout supprimer. Les contenus
 * sans créateur connu (créés avant cette règle) ne sont donc
 * supprimables que par un entraîneur ou un admin.
 */
class ContentDeleteVoter extends Voter
{
    public const ATTRIBUTE = 'CONTENT_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof OwnedContentInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($user->isAdmin() || $user->isEntraineurAdmin()) {
            return true;
        }

        $owner = $subject->getOwner();
        return $owner !== null && $owner->getId() === $user->getId();
    }
}
