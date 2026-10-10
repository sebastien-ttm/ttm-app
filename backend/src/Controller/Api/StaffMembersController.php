<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Repository\UserSeasonMembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annuaire des adhérents pour le staff sportif (espace « Gestion » de l'appli) :
 * nom, prénom et numéro de téléphone, pour pouvoir appeler en cas d'urgence, et
 * appartenance à la liste des adhérents de la saison en cours.
 *
 * Données personnelles : réservé aux profils Entraîneur et Encadrant, et
 * limité à l'identité et au téléphone (rien d'autre n'est exposé).
 */
#[IsGranted('ROLE_USER')]
class StaffMembersController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
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

        // Saison en cours (même règle que l'admin : celle qui contient aujourd'hui, sinon la
        // plus récente) et sa liste d'adhérents, issue de l'import FFTri.
        $season = $this->seasons->findCurrent();
        $inSeason = $season !== null ? array_flip($this->memberships->findUserIdsForSeason($season)) : [];

        $members = array_map(static function (User $u) use ($inSeason): array {
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
                // Présent dans la liste des adhérents de la saison en cours ?
                'inCurrentSeason' => isset($inSeason[$u->getId()]),
            ];
        }, $this->users->findAdherentsForStaffDirectory());

        return new JsonResponse([
            'data' => $members,
            'total' => count($members),
            // memberCount = 0 : la liste de la saison n'est pas encore importée, le
            // marquage n'aurait aucun sens (tout le monde paraîtrait « hors liste »).
            'season' => $season === null ? null : [
                'id' => $season->getId(),
                'label' => (string) $season,
                'memberCount' => count($inSeason),
            ],
        ]);
    }

    private static function cleanPhone(?string $raw): ?string
    {
        $phone = $raw !== null ? trim($raw) : '';

        return $phone !== '' ? $phone : null;
    }
}
