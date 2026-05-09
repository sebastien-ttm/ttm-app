<?php

namespace App\Controller\Api;

use App\Repository\MenuItemRepository;
use App\Service\Serializer\ApiSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class MenuController extends AbstractController
{
    public function __construct(
        private readonly MenuItemRepository $items,
        private readonly ApiSerializer $serializer,
    ) {
    }

    #[Route('/api/menu', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(
                fn ($i) => $this->serializer->menuItem($i),
                $this->items->findVisibleOrdered()
            ),
        ]);
    }
}
