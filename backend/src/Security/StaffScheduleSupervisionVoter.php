<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attribut `STAFF_SCHEDULE_SUPERVISION` : réservé à l'entraîneur référent
 * (case « Entraîneur référent » de la fiche adhérent) et aux admins, pas
 * à tous les comptes ROLE_ENTRAINEUR. Autorise à modifier les présences
 * du staff dans « Présence entraînements » (les autres entraîneurs
 * la voient en lecture seule) et à éditer les « Semaines types
 * entraîneurs ».
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
