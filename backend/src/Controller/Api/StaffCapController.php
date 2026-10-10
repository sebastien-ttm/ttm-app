<?php

namespace App\Controller\Api;

use App\Entity\CapDistribution;
use App\Entity\User;
use App\Repository\CapDistributionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Émargement de la remise des bonnets de bain du club depuis l'espace « Staff »
 * de l'appli (même logique que CapDistributionController dans le backend) : la
 * liste des adhérents actifs avec leur état, « Remis » (première remise ou
 * remplacement) et annulation de la dernière remise en cas de clic par erreur.
 *
 * Réservé aux entraîneurs (profil Entraîneur) et aux administrateurs.
 */
#[IsGranted('ROLE_USER')]
class StaffCapController extends AbstractController
{
    use StaffOnlyTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly CapDistributionRepository $distributions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/staff/caps', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);

        $byUser = $this->distributions->findAllGroupedByUser();
        $rows = array_map(static fn (User $u) => [
            'id' => $u->getId(),
            'nom' => $u->getNom(),
            'prenom' => $u->getPrenom(),
            'categorie' => $u->getCategorieFFTri(),
        ] + self::state($byUser[$u->getId()] ?? []), $this->users->findActiveAdherentsForRecap());

        return new JsonResponse([
            'data' => $rows,
            'total' => count($rows),
            'received' => count(array_filter($rows, static fn (array $r) => $r['count'] > 0)),
        ]);
    }

    /** Enregistre une remise (première ou remplacement). Réponse : nouvel état. */
    #[Route('/api/staff/caps/{userId}/give', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function give(int $userId): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);
        $user = $this->users->find($userId) ?? throw $this->createNotFoundException('Adhérent introuvable.');

        $this->em->persist(new CapDistribution($user, $viewer));
        $this->em->flush();

        return new JsonResponse(self::state($this->distributions->findByUser($user)));
    }

    /** Annule la dernière remise d'un adhérent (clic par erreur). Réponse : nouvel état. */
    #[Route('/api/staff/caps/{userId}/undo', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function undo(int $userId): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);
        $user = $this->users->find($userId) ?? throw $this->createNotFoundException('Adhérent introuvable.');

        $list = $this->distributions->findByUser($user);
        if ($list !== []) {
            $this->em->remove(array_shift($list));
            $this->em->flush();
        }

        return new JsonResponse(self::state($list));
    }

    /**
     * @param list<CapDistribution> $list remises, la plus récente d'abord
     * @return array{count: int, lastAt: ?string, lastBy: ?string}
     */
    private static function state(array $list): array
    {
        $last = $list[0] ?? null;

        return [
            'count' => count($list),
            'lastAt' => $last?->getDistributedAt()->format('d/m/Y'),
            'lastBy' => $last?->getDistributedBy()?->getFullName(),
        ];
    }
}
