<?php

namespace App\Controller\Admin;

use App\Repository\StaticPageAttachmentRepository;
use App\Repository\StaticPageRepository;
use App\Service\StaticPage\StaticPageAttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des pièces jointes d'une page statique : page dédiée listant
 * les PJ actuelles + upload + suppression. Accessible depuis l'arbre des
 * pages (bouton 📎) et depuis l'édition d'une page. Miroir de
 * ArticleAttachmentController.
 */
#[IsGranted('ROLE_EDITEUR')]
class StaticPageAttachmentController extends AbstractController
{
    use EnsureAdminContextTrait;

    /** 10 Mo max par fichier — même limite que les PJ d'articles. */
    private const MAX_BYTES = 10_000_000;

    public function __construct(
        private readonly StaticPageRepository $pages,
        private readonly StaticPageAttachmentRepository $attachments,
        private readonly StaticPageAttachmentService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/page/{id}/attachments', name: 'admin_static_page_attachments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function index(int $id, Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_static_page_attachments')) {
            return $r;
        }
        $page = $this->pages->find($id);
        if ($page === null) {
            throw $this->createNotFoundException();
        }
        return $this->render('admin/static_page_attachments.html.twig', [
            'page' => $page,
            'maxMB' => (int) (self::MAX_BYTES / 1_000_000),
        ]);
    }

    #[Route('/admin/page/{id}/attachments/upload', name: 'admin_static_page_attachment_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function upload(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'static_page_attachment');
        $page = $this->pages->find($id);
        if ($page === null) {
            throw $this->createNotFoundException();
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('error', 'Fichier invalide ou manquant.');
            return $this->redirectToRoute('admin_static_page_attachments', ['id' => $id]);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            $this->addFlash('error', sprintf('Fichier trop volumineux (max %d Mo).', (int) (self::MAX_BYTES / 1_000_000)));
            return $this->redirectToRoute('admin_static_page_attachments', ['id' => $id]);
        }

        try {
            $this->service->upload($page, $file);
            $this->em->flush();
            $this->addFlash('success', sprintf('Pièce jointe « %s » ajoutée.', $file->getClientOriginalName()));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Échec de l\'upload : '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_static_page_attachments', ['id' => $id]);
    }

    #[Route('/admin/page/{id}/attachments/{attId}/delete', name: 'admin_static_page_attachment_delete', methods: ['POST'], requirements: ['id' => '\d+', 'attId' => '\d+'])]
    public function delete(int $id, int $attId, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'static_page_attachment');
        $att = $this->attachments->find($attId);
        if ($att === null || $att->getPage()->getId() !== $id) {
            throw $this->createNotFoundException();
        }
        $name = $att->getOriginalName();
        $this->service->remove($att);
        $this->em->flush();
        $this->addFlash('success', sprintf('Pièce jointe « %s » supprimée.', $name));
        return $this->redirectToRoute('admin_static_page_attachments', ['id' => $id]);
    }

    private function validateCsrf(Request $request, string $intent): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($intent, $token)) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }
}
