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
 * Trombinoscope Comité — page publique pour tous les adhérents.
 * Regroupe :
 *  - bureau  : Président + Trésorier + Secrétaire
 *  - codir   : Membres du CoDir (hors bureau)
 *  - coaches : Entraîneurs (profil Entraineur)
 *
 * L'union bureau ∪ codir ∪ coaches peut contenir des doublons (un
 * entraîneur peut aussi être trésorier) — c'est l'affichage côté
 * client qui décide de la présentation (chaque section a sa propre
 * liste ; le client peut dédupliquer s'il veut).
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
        $coaches = $this->users->findCoaches();

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

        $coachesOut = array_map(
            fn (User $u) => $this->serializer->committeeMember($u, $this->avatars->urlFor($u)),
            $coaches,
        );

        return new JsonResponse([
            'bureau' => $bureau,
            'codir' => $codir,
            'coaches' => $coachesOut,
        ]);
    }
}
