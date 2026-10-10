<?php

namespace App\Controller\Api;

use App\Entity\CapDistribution;
use App\Entity\User;
use App\Repository\CapDistributionRepository;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    use StaffLiveStateTrait;

    public function __construct(
        private readonly UserRepository $users,
        private readonly CapDistributionRepository $distributions,
        private readonly EntityManagerInterface $em,
        private readonly AvatarService $avatars,
    ) {
    }

    #[Route('/api/staff/caps', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);

        $byUser = $this->distributions->findAllGroupedByUser();
        $rows = array_map(fn (User $u) => [
            'id' => $u->getId(),
            'nom' => $u->getNom(),
            'prenom' => $u->getPrenom(),
            // URL publique de la photo (carrée), null si l'adhérent n'en a pas : sert à le reconnaître.
            'avatarUrl' => $this->avatars->urlFor($u),
            'categorie' => $u->getCategorieFFTri(),
        ] + self::state($byUser[$u->getId()] ?? []), $this->users->findActiveAdherentsForRecap());

        return new JsonResponse([
            'data' => $rows,
            'total' => count($rows),
            'received' => count(array_filter($rows, static fn (array $r) => $r['count'] > 0)),
        ]);
    }

    /**
     * État en direct de l'écran (voir StaffLiveStateTrait) : les adhérents qui ont reçu au moins
     * un bonnet. Interrogé toutes les quelques secondes par l'appli pour que plusieurs personnes
     * voient en direct le travail des autres.
     */
    #[Route('/api/staff/caps/state', methods: ['GET'])]
    public function live(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);

        $rows = [];
        foreach ($this->distributions->findAllGroupedByUser() as $userId => $list) {
            $rows[] = ['id' => $userId] + self::state($list);
        }
        usort($rows, static fn (array $a, array $b) => $a['id'] <=> $b['id']);

        return $this->liveState($request, ['rows' => $rows]);
    }

    /**
     * Enregistre une remise (première ou remplacement). Réponse : nouvel état.
     *
     * Body facultatif : { expectedCount } = nombre de remises affiché par l'appli. Si un autre membre
     * du staff est passé entre-temps (le nombre n'est plus celui-là), RIEN n'est enregistré et la
     * réponse est un 409 avec l'état actuel : un double appui simultané ne compte pas deux bonnets.
     */
    #[Route('/api/staff/caps/{userId}/give', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function give(int $userId, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);
        $user = $this->users->find($userId) ?? throw $this->createNotFoundException('Adhérent introuvable.');
        $expected = self::expectedCount($request);

        $outcome = $this->em->wrapInTransaction(function () use ($user, $viewer, $expected): array {
            // Verrou sur l'adhérent : deux remises simultanées pour lui s'enchaînent au lieu de se croiser.
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            $list = $this->distributions->findByUser($user);
            if ($expected !== null && count($list) !== $expected) {
                return ['conflict' => true, 'list' => $list];
            }
            $this->em->persist(new CapDistribution($user, $viewer));
            $this->em->flush();

            return ['conflict' => false, 'list' => $this->distributions->findByUser($user)];
        });

        if ($outcome['conflict']) {
            $list = $outcome['list'];
            $by = ($list[0] ?? null)?->getDistributedBy()?->getFullName() ?? 'un autre membre du staff';
            $message = $expected === 0
                ? sprintf('%s a déjà reçu son bonnet (remis par %s) : rien de plus n\'a été enregistré.', $user->getFullName(), $by)
                : sprintf('Le nombre de remises de %s a changé entre-temps (%d maintenant) : vérifiez, puis recommencez si besoin.', $user->getFullName(), count($list));

            return new JsonResponse(['error' => $message, 'state' => self::state($list)], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(self::state($outcome['list']));
    }

    /**
     * Annule la dernière remise d'un adhérent (clic par erreur). Réponse : nouvel état.
     * Même protection que `give` : si le nombre de remises n'est plus celui que l'appli affichait
     * (`expectedCount`), rien n'est retiré (409) — deux annulations simultanées n'en retirent qu'une.
     */
    #[Route('/api/staff/caps/{userId}/undo', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function undo(int $userId, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessCapsAndTimes($viewer);
        $user = $this->users->find($userId) ?? throw $this->createNotFoundException('Adhérent introuvable.');
        $expected = self::expectedCount($request);

        $outcome = $this->em->wrapInTransaction(function () use ($user, $expected): array {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            $list = $this->distributions->findByUser($user);
            if ($expected !== null && count($list) !== $expected) {
                return ['conflict' => true, 'list' => $list];
            }
            if ($list !== []) {
                $this->em->remove(array_shift($list));
                $this->em->flush();
            }

            return ['conflict' => false, 'list' => $list];
        });

        if ($outcome['conflict']) {
            $message = sprintf('Le nombre de remises de %s a changé entre-temps (%d maintenant) : rien n\'a été retiré.', $user->getFullName(), count($outcome['list']));

            return new JsonResponse(['error' => $message, 'state' => self::state($outcome['list'])], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(self::state($outcome['list']));
    }

    /** Nombre de remises que l'appli affichait au moment de l'appui (null si non fourni : pas de contrôle). */
    private static function expectedCount(Request $request): ?int
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) && isset($payload['expectedCount']) && is_int($payload['expectedCount'])
            ? $payload['expectedCount']
            : null;
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
