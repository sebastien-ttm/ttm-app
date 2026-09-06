<?php

namespace App\Controller\Api;

use App\Entity\AdminNotice;
use App\Entity\AdminNoticeAcknowledgement;
use App\Entity\User;
use App\Repository\AdminNoticeAcknowledgementRepository;
use App\Repository\AdminNoticeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Notices ponctuelles admin avec acquittement obligatoire — décorrélé
 * du tunnel charte (début de saison). Le mobile poll `/pending` au
 * démarrage et au retour de background après un temps d'inactivité,
 * puis affiche chaque notice pendante en modal FIFO jusqu'à
 * acquittement.
 */
#[IsGranted('ROLE_USER')]
class NoticeController extends AbstractController
{
    public function __construct(
        private readonly AdminNoticeRepository $notices,
        private readonly AdminNoticeAcknowledgementRepository $acks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/me/notices/pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $rows = $this->notices->findPendingFor($user);
        return new JsonResponse([
            'data' => array_map(fn (AdminNotice $n) => $this->serialize($n), $rows),
        ]);
    }

    /**
     * Enregistre l'acquittement. Idempotent : rejoue une notification
     * déjà acquittée renvoie 200 sans double-écriture.
     */
    #[Route('/api/me/notices/{id}/acknowledge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acknowledge(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $notice = $this->notices->find($id);
        if ($notice === null) {
            return new JsonResponse(['error' => 'Notice introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $existing = $this->acks->findOneByUserAndNotice($user, $notice);
        if ($existing !== null) {
            return new JsonResponse([
                'ok' => true,
                'alreadyAcknowledged' => true,
                'acknowledgedAt' => $existing->getAcknowledgedAt()->format(\DATE_ATOM),
            ]);
        }

        $ack = new AdminNoticeAcknowledgement($user, $notice);
        $this->em->persist($ack);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'acknowledgedAt' => $ack->getAcknowledgedAt()->format(\DATE_ATOM),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AdminNotice $n): array
    {
        return [
            'id' => $n->getId(),
            'title' => $n->getTitle(),
            'content' => $n->getContent(),
            'acknowledgeLabel' => $n->getAcknowledgeLabel(),
            'publishedAt' => $n->getPublishedAt()?->format(\DATE_ATOM),
            'expiresAt' => $n->getExpiresAt()?->format(\DATE_ATOM),
        ];
    }
}
