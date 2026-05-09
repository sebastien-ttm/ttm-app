<?php

namespace App\Controller\Api;

use App\Entity\TrainingPlan;
use App\Repository\TrainingPlanRepository;
use App\Service\Serializer\ApiSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api/training-plans')]
class TrainingPlanController extends AbstractController
{
    public function __construct(
        private readonly TrainingPlanRepository $plans,
        private readonly ApiSerializer $serializer,
        private readonly string $trainingDir,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $paginator = $this->plans->findPaginated($page, $limit);
        $total = count($paginator);

        return new JsonResponse([
            'data' => array_map(
                fn (TrainingPlan $p) => $this->serializer->trainingPlan($p),
                iterator_to_array($paginator)
            ),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(TrainingPlan $plan): JsonResponse
    {
        return new JsonResponse($this->serializer->trainingPlan($plan));
    }

    #[Route('/{id}/file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadFile(TrainingPlan $plan): BinaryFileResponse
    {
        $path = $plan->getFilePath();
        if ($path === null) {
            throw $this->createNotFoundException();
        }
        $absolute = rtrim($this->trainingDir, '/\\').\DIRECTORY_SEPARATOR.$path;
        if (!is_file($absolute)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($absolute);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $plan->getTitle().'.pdf'
        );
        $response->headers->set('Content-Type', 'application/pdf');
        return $response;
    }
}
