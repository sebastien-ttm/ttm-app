<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\BoardRole;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\Serializer\ApiSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Trombinoscopes publics (adhérents connectés) :
 *
 *  - /api/committee : { bureau, codir }
 *    Gouvernance : Bureau (Président + Trésorier + Secrétaire) +
 *    Membres du CoDir (hors bureau).
 *
 *  - /api/staff : { coaches, encadrants }
 *    Vue « staff sportif » — Entraîneurs + Encadrants, populations
 *    éventuellement recouvertes (un entraîneur peut aussi encadrer
 *    un créneau) : chacun apparait dans la section où son profil le
 *    range.
 */
#[IsGranted('ROLE_USER')]
class CommitteeController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ApiSerializer $serializer,
        private readonly AvatarService $avatars,
    ) {
    }

    #[Route('/api/committee', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $committee = $this->users->findCommitteeMembers();

        // Trie du CoDir par ordre canonique du rôle (Président en tête).
        usort($committee, static function (User $a, User $b): int {
            $oa = $a->getBoardRole()?->displayOrder() ?? 99;
            $ob = $b->getBoardRole()?->displayOrder() ?? 99;
            return $oa <=> $ob ?: strcmp((string) $a->getNom(), (string) $b->getNom());
        });

        $bureau = [];
        $codir = [];
        foreach ($committee as $u) {
            $entry = $this->serializer->committeeMember($u, $this->avatars->urlFor($u));
            if ($u->getBoardRole()?->isBureau()) {
                $bureau[] = $entry;
            } else {
                $codir[] = $entry;
            }
        }

        return new JsonResponse([
            'bureau' => $bureau,
            'codir' => $codir,
        ]);
    }

    #[Route('/api/staff', methods: ['GET'])]
    public function staff(): JsonResponse
    {
        $coaches = $this->users->findCoaches();
        $encadrants = $this->users->findEncadrants();

        $map = fn (User $u) => $this->serializer->committeeMember($u, $this->avatars->urlFor($u));

        return new JsonResponse([
            'coaches' => array_map($map, $coaches),
            'encadrants' => array_map($map, $encadrants),
        ]);
    }
}
