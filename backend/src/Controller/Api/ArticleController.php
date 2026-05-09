<?php

namespace App\Controller\Api;

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\Reaction;
use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Repository\CommentRepository;
use App\Repository\ReactionRepository;
use App\Service\Serializer\ApiSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_USER')]
#[Route('/api/articles')]
class ArticleController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly CommentRepository $comments,
        private readonly ReactionRepository $reactions,
        private readonly ApiSerializer $serializer,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $paginator = $this->articles->findPublishedPaginated($page, $limit);
        $total = count($paginator);

        $items = [];
        foreach ($paginator as $article) {
            $items[] = $this->serializer->article($article, $viewer);
        }

        return new JsonResponse([
            'data' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $limit)),
        ]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(Article $article): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$article->isPublished()) {
            throw $this->createNotFoundException();
        }
        return new JsonResponse($this->serializer->article($article, $viewer));
    }

    #[Route('/{id}/comments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listComments(Article $article, Request $request): JsonResponse
    {
        if (!$article->isPublished()) {
            throw $this->createNotFoundException();
        }
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $paginator = $this->comments->findByArticlePaginated($article, $page, $limit);
        $total = count($paginator);

        return new JsonResponse([
            'data' => array_map(
                fn (Comment $c) => $this->serializer->comment($c),
                iterator_to_array($paginator)
            ),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    #[Route('/{id}/comments', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addComment(Article $article, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$article->isPublished()) {
            throw $this->createNotFoundException();
        }
        $payload = json_decode($request->getContent(), true);
        $content = is_array($payload) ? trim((string) ($payload['content'] ?? '')) : '';

        if ($content === '' || mb_strlen($content) > 2000) {
            return new JsonResponse(['error' => 'Le commentaire doit faire entre 1 et 2000 caractères.'], Response::HTTP_BAD_REQUEST);
        }

        $comment = new Comment($article, $user, $content);
        $errors = $this->validator->validate($comment);
        if (count($errors) > 0) {
            return new JsonResponse(['error' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($comment);
        $this->em->flush();

        return new JsonResponse($this->serializer->comment($comment), Response::HTTP_CREATED);
    }

    #[Route('/{id}/reactions', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function toggleReaction(Article $article, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$article->isPublished()) {
            throw $this->createNotFoundException();
        }
        $payload = json_decode($request->getContent(), true);
        $emoji = is_array($payload) ? trim((string) ($payload['emoji'] ?? '')) : '';

        if (!in_array($emoji, Reaction::ALLOWED_EMOJIS, true)) {
            return new JsonResponse([
                'error' => 'Émoticon non autorisé.',
                'allowed' => Reaction::ALLOWED_EMOJIS,
            ], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->reactions->findOne($article, $user, $emoji);
        if ($existing !== null) {
            $this->em->remove($existing);
            $action = 'removed';
        } else {
            $this->em->persist(new Reaction($article, $user, $emoji));
            $action = 'added';
        }
        $this->em->flush();

        // Refresh counts
        $this->em->refresh($article);
        return new JsonResponse([
            'action' => $action,
            'emoji' => $emoji,
            'reactionCounts' => $article->getReactionCounts(),
        ]);
    }
}
