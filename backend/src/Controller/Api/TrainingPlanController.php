<?php

namespace App\Controller\Api;

use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Repository\TrainingPlanRepository;
use App\Service\Audience\AudienceFilter;
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
        private readonly AudienceFilter $audienceFilter,
        private readonly string $trainingDir,
    ) {
    }

    private function ensureVisible(TrainingPlan $plan, ?User $viewer): void
    {
        if (!$this->audienceFilter->isVisible($plan->getAudience(), $viewer)) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $paginator = $this->plans->findPaginated($page, $limit, $viewer);
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
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->ensureVisible($plan, $viewer);
        return new JsonResponse($this->serializer->trainingPlan($plan));
    }

    #[Route('/{id}/file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadFile(TrainingPlan $plan): BinaryFileResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->ensureVisible($plan, $viewer);
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
