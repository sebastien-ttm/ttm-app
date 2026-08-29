<?php

namespace App\Controller\Admin;

use App\Entity\StaticPage;
use App\Repository\StaticPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page dédiée à la réorganisation des pages statiques par drag-and-drop.
 * L'admin voit un arbre à 2 niveaux (racines + sous-pages), glisse pour
 * réordonner, et un POST atomique persiste le nouvel ordre + éventuel
 * changement de parent en batch.
 */
#[IsGranted('ROLE_EDITEUR')]
class StaticPageReorderController extends AbstractController
{
    public function __construct(
        private readonly StaticPageRepository $pages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/pages/reorder', name: 'admin_pages_reorder', methods: ['GET'])]
    public function index(): Response
    {
        $all = $this->pages->createQueryBuilder('p')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();

        // Regroupe par parent pour affichage arborescent 2 niveaux.
        $roots = [];
        $childrenByParent = [];
        /** @var StaticPage $page */
        foreach ($all as $page) {
            $parent = $page->getParent();
            if ($parent === null) {
                $roots[] = $page;
            } else {
                $childrenByParent[$parent->getId()][] = $page;
            }
        }

        return $this->render('admin/pages_reorder.html.twig', [
            'roots' => $roots,
            'childrenByParent' => $childrenByParent,
        ]);
    }

    /**
     * Persiste le nouvel ordre. Payload JSON attendu :
     * [
     *   { "id": 12, "parentId": null, "position": 0 },
     *   { "id": 8,  "parentId": 12,   "position": 0 },
     *   ...
     * ]
     */
    #[Route('/admin/pages/reorder', name: 'admin_pages_reorder_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        // CSRF : token dans l'en-tête (le drag-drop est un fetch JS, pas un
        // formulaire multipart — on utilise donc l'intent applicatif).
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('pages_reorder', $token)) {
            return new JsonResponse(['error' => 'CSRF invalide'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide'], Response::HTTP_BAD_REQUEST);
        }

        // Précharge tout en 1 requête pour éviter N SELECT.
        $ids = array_values(array_filter(array_map(
            fn ($row) => is_array($row) && isset($row['id']) ? (int) $row['id'] : null,
            $payload,
        )));
        if ($ids === []) {
            return new JsonResponse(['ok' => true, 'updated' => 0]);
        }
        /** @var array<int, StaticPage> $byId */
        $byId = [];
        foreach ($this->pages->findBy(['id' => $ids]) as $p) {
            $byId[$p->getId()] = $p;
        }

        $updated = 0;
        try {
            foreach ($payload as $row) {
                if (!is_array($row) || !isset($row['id'], $row['position'])) {
                    continue;
                }
                $id = (int) $row['id'];
                $page = $byId[$id] ?? null;
                if ($page === null) {
                    continue;
                }
                $newPosition = (int) $row['position'];
                $newParentId = isset($row['parentId']) && $row['parentId'] !== null
                    ? (int) $row['parentId']
                    : null;

                $newParent = $newParentId !== null ? ($byId[$newParentId] ?? $this->pages->find($newParentId)) : null;

                // setParent() garde-fou anti-cycle interne (throw si self ou descendant)
                if ($page->getParent()?->getId() !== $newParent?->getId()) {
                    $page->setParent($newParent);
                }
                if ($page->getPosition() !== $newPosition) {
                    $page->setPosition($newPosition);
                }
                $updated++;
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['ok' => true, 'updated' => $updated]);
    }
}
