<?php

namespace App\Controller\Api;

use App\Entity\CharterAcceptance;
use App\Entity\User;
use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
use App\Service\Serializer\ApiSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CharterController extends AbstractController
{
    public function __construct(
        private readonly ClubCharterRepository $charters,
        private readonly CharterAcceptanceRepository $acceptances,
        private readonly EntityManagerInterface $em,
        private readonly ApiSerializer $serializer,
    ) {
    }

    /**
     * Returns the currently-active charter and whether the current user
     * still needs to accept it.
     */
    #[Route('/api/charter/current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $charter = $this->charters->findCurrent();

        if ($charter === null) {
            return new JsonResponse([
                'charter' => null,
                'acceptanceRequired' => false,
            ]);
        }

        $hasAccepted = $this->acceptances->hasAccepted($user, $charter);

        return new JsonResponse([
            'charter' => $this->serializer->charter($charter),
            'acceptanceRequired' => !$hasAccepted,
        ]);
    }

    /**
     * Records the user's acceptance of the currently-active charter.
     */
    #[Route('/api/me/charter/accept', methods: ['POST'])]
    public function accept(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $charter = $this->charters->findCurrent();

        if ($charter === null) {
            return new JsonResponse(
                ['error' => 'Aucune charte active.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($this->acceptances->hasAccepted($user, $charter)) {
            // Idempotent : déjà acceptée
            return new JsonResponse(['ok' => true, 'alreadyAccepted' => true]);
        }

        $acceptance = new CharterAcceptance($user, $charter, $request->getClientIp());
        $this->em->persist($acceptance);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'acceptedAt' => $acceptance->getAcceptedAt()->format(\DATE_ATOM),
        ]);
    }
}
