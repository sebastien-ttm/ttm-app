<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annuaire des adhérents pour le staff sportif (espace « Gestion » de l'appli) :
 * nom, prénom et numéro de téléphone, pour pouvoir appeler en cas d'urgence.
 *
 * Données personnelles : réservé aux profils Entraîneur et Encadrant, et
 * limité à l'identité et au téléphone (rien d'autre n'est exposé).
 */
#[IsGranted('ROLE_USER')]
class StaffMembersController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/api/staff/members', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$viewer->isEntraineur() && !$viewer->isEncadrant()) {
            throw $this->createAccessDeniedException();
        }

        $members = array_map(static function (User $u): array {
            $phone = self::cleanPhone($u->getTelephone());
            $phoneOf = null;
            // Enfant sans numéro : celui d'un parent, pour pouvoir joindre quelqu'un en cas d'urgence.
            if ($phone === null) {
                foreach ($u->getParents() as $parent) {
                    $parentPhone = self::cleanPhone($parent->getTelephone());
                    if ($parentPhone !== null) {
                        $phone = $parentPhone;
                        $phoneOf = $parent->getFullName();
                        break;
                    }
                }
            }

            return [
                'id' => $u->getId(),
                'nom' => $u->getNom(),
                'prenom' => $u->getPrenom(),
                'telephone' => $phone,
                // Non null quand le numéro est celui d'un parent (« Appeler Marie Dupont »).
                'telephoneOf' => $phoneOf,
            ];
        }, $this->users->findAdherentsForStaffDirectory());

        return new JsonResponse(['data' => $members, 'total' => count($members)]);
    }

    private static function cleanPhone(?string $raw): ?string
    {
        $phone = $raw !== null ? trim($raw) : '';

        return $phone !== '' ? $phone : null;
    }
}
