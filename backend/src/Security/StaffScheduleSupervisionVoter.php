<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attribut `STAFF_SCHEDULE_SUPERVISION` (voir StaffPresenceController)
 * : ouvre la page backend « Emploi du temps entraîneurs » aux seuls
 * entraîneurs explicitement désignés gestionnaires (ou aux admins),
 * pas à tous les comptes ROLE_ENTRAINEUR.
 */
class StaffScheduleSupervisionVoter extends Voter
{
    public const ATTRIBUTE = 'STAFF_SCHEDULE_SUPERVISION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user instanceof User && ($user->isAdmin() || $user->canManageTrainerSchedule());
    }
}
