<?php

namespace App\Controller\Api;

use App\Entity\User;

/**
 * Accès réservé au staff sportif (profils Entraîneur et Encadrant) pour les
 * endpoints /api/staff/* de l'espace « Staff » de l'appli. À utiliser dans un
 * contrôleur qui étend AbstractController.
 */
trait StaffOnlyTrait
{
    private function denyUnlessStaff(User $viewer): void
    {
        if (!$viewer->isEntraineur() && !$viewer->isEncadrant()) {
            throw $this->createAccessDeniedException();
        }
    }
}
