<?php

namespace App\Controller\Api;

use App\Repository\TrainingSlotAttachmentRepository;
use App\Service\Training\AttachmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pièce jointe de créneau via lien temporaire signé (voir
 * TrainingScheduleController::temporaryLink), SANS authentification :
 * firewall « api_public » (^/api/public). La signature (HMAC sur id +
 * expiration) et l'expiration (15 min) remplacent le jeton de connexion.
 */
class PublicAttachmentController extends AbstractController
{
    public function __construct(
        private readonly TrainingSlotAttachmentRepository $attachments,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    #[Route(
        '/api/public/attachments/{id}/{expires}/{signature}/{name}',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'expires' => '\d+', 'signature' => '[a-f0-9]{40}', 'name' => '[^/]+'],
    )]
    public function download(int $id, int $expires, string $signature): BinaryFileResponse
    {
        if (!$this->attachmentService->isValidTemporaryLink($id, $expires, $signature)) {
            throw $this->createNotFoundException();
        }
        $att = $this->attachments->find($id);
        $resp = $att !== null ? $this->attachmentService->fileResponse($att) : null;
        if ($resp === null) {
            throw $this->createNotFoundException();
        }
        $resp->setPrivate();
        $resp->headers->addCacheControlDirective('no-store');
        return $resp;
    }
}
