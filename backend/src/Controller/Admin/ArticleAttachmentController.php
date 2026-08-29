<?php

namespace App\Controller\Admin;

use App\Repository\ArticleAttachmentRepository;
use App\Repository\ArticleRepository;
use App\Service\Article\ArticleAttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des pièces jointes d'un article : page dédiée listant les PJ
 * actuelles + upload + suppression. Accessible via un lien depuis le CRUD
 * Article (bouton « Gérer les pièces jointes » sur la vue Détail).
 */
#[IsGranted('ROLE_EDITEUR')]
class ArticleAttachmentController extends AbstractController
{
    /** 10 Mo max par fichier — même limite qu'un plan d'entraînement. */
    private const MAX_BYTES = 10_000_000;

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ArticleAttachmentRepository $attachments,
        private readonly ArticleAttachmentService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/article/{id}/attachments', name: 'admin_article_attachments', requirements: ['id' => '\d+'])]
    public function index(int $id): Response
    {
        $article = $this->articles->find($id);
        if ($article === null) {
            throw $this->createNotFoundException();
        }
        return $this->render('admin/article_attachments.html.twig', [
            'article' => $article,
            'maxMB' => (int) (self::MAX_BYTES / 1_000_000),
        ]);
    }

    #[Route('/admin/article/{id}/attachments/upload', name: 'admin_article_attachment_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function upload(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'article_attachment');
        $article = $this->articles->find($id);
        if ($article === null) {
            throw $this->createNotFoundException();
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('error', 'Fichier invalide ou manquant.');
            return $this->redirectToRoute('admin_article_attachments', ['id' => $id]);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            $this->addFlash('error', sprintf('Fichier trop volumineux (max %d Mo).', (int) (self::MAX_BYTES / 1_000_000)));
            return $this->redirectToRoute('admin_article_attachments', ['id' => $id]);
        }

        try {
            $this->service->upload($article, $file);
            $this->em->flush();
            $this->addFlash('success', sprintf('Pièce jointe « %s » ajoutée.', $file->getClientOriginalName()));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Échec de l\'upload : '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_article_attachments', ['id' => $id]);
    }

    #[Route('/admin/article/{id}/attachments/{attId}/delete', name: 'admin_article_attachment_delete', methods: ['POST'], requirements: ['id' => '\d+', 'attId' => '\d+'])]
    public function delete(int $id, int $attId, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'article_attachment');
        $att = $this->attachments->find($attId);
        if ($att === null || $att->getArticle()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        $name = $att->getOriginalName();
        $this->service->remove($att);
        $this->em->flush();
        $this->addFlash('success', sprintf('Pièce jointe « %s » supprimée.', $name));
        return $this->redirectToRoute('admin_article_attachments', ['id' => $id]);
    }

    private function validateCsrf(Request $request, string $intent): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($intent, $token)) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }
}
