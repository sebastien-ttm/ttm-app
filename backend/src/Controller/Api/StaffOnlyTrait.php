<?php

namespace App\Controller\Api;

use App\Entity\User;

/**
 * Niveaux d'accès des endpoints /api/staff/* de l'espace « Staff » de l'appli.
 * À utiliser dans un contrôleur qui étend AbstractController.
 *
 *  - denyUnlessStaff      : profils Entraîneur et Encadrant (annuaire des adhérents) ;
 *  - denyUnlessCheckIn    : profil Entraîneur ou membre du CoDir (émargement des événements) ;
 *  - denyUnlessEntraineur : profil Entraîneur seul (remise des bonnets, saisie des temps).
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
        if (!$viewer->isEntraineur() && $viewer->getBoardRole() === null) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessEntraineur(User $viewer): void
    {
        if (!$viewer->isEntraineur()) {
            throw $this->createAccessDeniedException();
        }
    }
}
