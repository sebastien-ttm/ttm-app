<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\StaticPageRepository;
use App\Service\Serializer\ApiSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api/pages')]
class StaticPageController extends AbstractController
{
    public function __construct(
        private readonly StaticPageRepository $pages,
        private readonly ApiSerializer $serializer,
    ) {
    }

    /**
     * Flat list of all published pages — kept for backward compat.
     */
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $items = array_map(
            fn ($p) => ['slug' => $p->getSlug(), 'title' => $p->getTitle()],
            $this->pages->findAllPublished($viewer)
        );
        return new JsonResponse(['data' => $items]);
    }

    /**
     * Hierarchical tree (only roots and their descendants).
     */
    #[Route('/tree', methods: ['GET'])]
    public function tree(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $roots = $this->pages->findRootsPublished($viewer);
        $tree = array_map(
            fn ($p) => $this->serializer->staticPageNode($p),
            $roots,
        );
        return new JsonResponse(['data' => $tree]);
    }

    #[Route('/{slug}', methods: ['GET'])]
    public function get(string $slug): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $page = $this->pages->findOneBySlugPublished($slug, $viewer);
        if ($page === null) {
            throw $this->createNotFoundException();
        }
        return new JsonResponse($this->serializer->staticPage($page, includeChildren: true));
    }
}
