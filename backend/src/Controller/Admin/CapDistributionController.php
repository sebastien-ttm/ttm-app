<?php

namespace App\Controller\Admin;

use App\Entity\CapDistribution;
use App\Entity\User;
use App\Repository\CapDistributionRepository;
use App\Repository\UserRepository;
use App\Security\CapDistributionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Émargement de la remise des bonnets de bain individuels du club :
 * liste des adhérents actifs avec recherche, un bouton « Remis » par
 * adhérent (sans rechargement de page, pratique sur téléphone au bord
 * du bassin), remplacement possible et annulation d'un clic erroné.
 * Accès : entraîneurs, admins et membres du CoDir (CapDistributionVoter).
 */
#[IsGranted(CapDistributionVoter::ATTRIBUTE)]
class CapDistributionController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'cap_distribution';

    public function __construct(
        private readonly UserRepository $users,
        private readonly CapDistributionRepository $distributions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/bonnets', name: 'admin_cap_distribution', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_cap_distribution')) {
            return $r;
        }

        $byUser = $this->distributions->findAllGroupedByUser();
        $rows = array_map(fn (User $u) => [
            'id' => $u->getId(),
            'nom' => $u->getNom(),
            'prenom' => $u->getPrenom(),
            'categorie' => $u->getCategorieFFTri(),
            'state' => self::state($byUser[$u->getId()] ?? []),
        ], $this->users->findActiveAdherentsForRecap());

        return $this->render('admin/cap_distribution.html.twig', [
            'rows' => $rows,
            'received' => count(array_filter($rows, fn ($r) => $r['state']['count'] > 0)),
        ]);
    }

    /** Enregistre une remise (première ou remplacement). Réponse : nouvel état. */
    #[Route('/admin/bonnets/{id}/remise', name: 'admin_cap_distribution_give', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function give(int $id, Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $user = $this->users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $by */
        $by = $this->getUser();
        $this->em->persist(new CapDistribution($user, $by));
        $this->em->flush();

        return new JsonResponse(self::state($this->distributions->findByUser($user)));
    }

    /** Annule la dernière remise d'un adhérent (clic par erreur). */
    #[Route('/admin/bonnets/{id}/annuler', name: 'admin_cap_distribution_undo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function undo(int $id, Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $user = $this->users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

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

    private function checkCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }
}
