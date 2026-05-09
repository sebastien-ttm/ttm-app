<?php

namespace App\Controller\Api;

use App\Repository\EventRepository;
use App\Service\Serializer\ApiSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class EventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly ApiSerializer $serializer,
    ) {
    }

    #[Route('/api/events', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $fromStr = (string) $request->query->get('from', '');
        $toStr = (string) $request->query->get('to', '');

        try {
            $from = $fromStr !== '' ? new \DateTimeImmutable($fromStr) : new \DateTimeImmutable('-1 month');
            $to = $toStr !== '' ? new \DateTimeImmutable($toStr) : new \DateTimeImmutable('+6 months');
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Date invalide.'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'data' => array_map(
                fn ($e) => $this->serializer->event($e),
                $this->events->findInRange($from, $to)
            ),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }
}
