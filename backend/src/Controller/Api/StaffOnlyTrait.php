<?php

namespace App\Controller\Api;

use App\Entity\User;

/**
 * Niveaux d'accès des endpoints /api/staff/* de l'espace « Staff » de l'appli.
 * À utiliser dans un contrôleur qui étend AbstractController.
 *
 *  - denyUnlessStaff       : profils Entraîneur et Encadrant (annuaire des adhérents) ;
 *  - denyUnlessCheckIn     : Entraîneur, Encadrant, membre du CoDir ou administrateur
 *                            (émargement des événements) ;
 *  - denyUnlessCapsAndTimes: Entraîneur ou administrateur (remise des bonnets,
 *                            saisie des temps).
 *
 * « Administrateur » = niveau d'accès admin du compte (User::isAdmin()).
 */
trait StaffOnlyTrait
{
    private function denyUnlessStaff(User $viewer): void
    {
        if (!$viewer->isEntraineur() && !$viewer->isEncadrant()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessCheckIn(User $viewer): void
    {
        if (!$viewer->isEntraineur() && !$viewer->isEncadrant() && $viewer->getBoardRole() === null && !$viewer->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessCapsAndTimes(User $viewer): void
    {
        if (!$viewer->isEntraineur() && !$viewer->isAdmin()) {
            throw $this->createAccessDeniedException();
        }
    }
}
