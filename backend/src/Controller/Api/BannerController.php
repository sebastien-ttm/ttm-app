<?php

namespace App\Controller\Api;

use App\Repository\BannerRepository;
use App\Service\Serializer\ApiSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class BannerController extends AbstractController
{
    public function __construct(
        private readonly BannerRepository $banners,
        private readonly ApiSerializer $serializer,
    ) {
    }

    #[Route('/api/banner/active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        $banner = $this->banners->findCurrentActive();
        if ($banner === null) {
            return new JsonResponse(['data' => null]);
        }
        return new JsonResponse(['data' => $this->serializer->banner($banner)]);
    }
}
