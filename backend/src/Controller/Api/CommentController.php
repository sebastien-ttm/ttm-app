<?php

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Service\Serializer\ApiSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Endpoint transverse pour l'édition d'un commentaire, qu'il soit
 * rattaché à un article ou à un événement — Comment porte l'un ou
 * l'autre (jamais les deux), donc une seule route suffit sans avoir
 * besoin de connaître le type d'hôte côté client.
 *
 * Seul l'auteur du commentaire peut le modifier. Pas de fenêtre de
 * temps limite (pas de demande en ce sens) — l'admin reste libre de
 * modérer/supprimer depuis le backend si besoin.
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/comments')]
class CommentController extends AbstractController
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly ApiSerializer $serializer,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $comment = $this->comments->find($id);
        if ($comment === null) {
            throw $this->createNotFoundException();
        }
        if ($comment->getUser()->getId() !== $viewer->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez modifier que vos propres commentaires.');
        }

        $payload = json_decode($request->getContent(), true);
        $content = is_array($payload) ? trim((string) ($payload['content'] ?? '')) : '';
        if ($content === '' || mb_strlen($content) > 2000) {
            return new JsonResponse(['error' => 'Le commentaire doit faire entre 1 et 2000 caractères.'], Response::HTTP_BAD_REQUEST);
        }

        $comment->edit($content);
        $errors = $this->validator->validate($comment);
        if (count($errors) > 0) {
            return new JsonResponse(['error' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return new JsonResponse($this->serializer->comment($comment));
    }
}
