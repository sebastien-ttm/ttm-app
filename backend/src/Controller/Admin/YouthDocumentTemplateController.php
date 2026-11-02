<?php

namespace App\Controller\Admin;

use App\Enum\YouthDocumentType;
use App\Service\YouthDocument\YouthDocumentService;
use App\Service\YouthDocument\YouthDocumentTemplateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modèles des documents des jeunes (fiche sanitaire, autorisation de sortie du territoire, autorisation de rentrer
 * seul) : pour chaque type, le modèle proposé au téléchargement dans l'appli. Un fichier déposé ici remplace le modèle
 * par défaut (formulaire Cerfa officiel, ou PDF du club généré par l'appli) ; le retirer rétablit le modèle par défaut.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class YouthDocumentTemplateController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const TYPE_REQUIREMENT = 'health_form|exit_authorization|go_home_alone';

    public function __construct(
        private readonly YouthDocumentTemplateService $templates,
        private readonly YouthDocumentService $documents,
    ) {
    }

    #[Route('/admin/youth-document-templates', name: 'admin_youth_document_templates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_youth_document_templates')) {
            return $r;
        }

        $rows = [];
        foreach (YouthDocumentType::cases() as $type) {
            $rows[] = [
                'type' => $type,
                'uploaded' => $this->templates->find($type),
                'officialUrl' => $type->officialTemplateUrl(),
            ];
        }

        return $this->render('admin/youth_document_templates.html.twig', [
            'rows' => $rows,
            'maxMB' => (int) (YouthDocumentTemplateService::MAX_BYTES / 1_000_000),
        ]);
    }

    #[Route('/admin/youth-document-templates/{type}/upload', name: 'admin_youth_document_template_upload', methods: ['POST'], requirements: ['type' => self::TYPE_REQUIREMENT])]
    public function upload(string $type, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $kind = YouthDocumentType::from($type);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'Fichier invalide ou manquant (trop volumineux ?).');

            return $this->redirectToRoute('admin_youth_document_templates');
        }
        try {
            $this->templates->store($kind, $file);
            $this->addFlash('success', sprintf('Modèle « %s » mis à jour : il est proposé dans l\'appli dès maintenant.', $kind->label()));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_youth_document_templates');
    }

    #[Route('/admin/youth-document-templates/{type}/delete', name: 'admin_youth_document_template_delete', methods: ['POST'], requirements: ['type' => self::TYPE_REQUIREMENT])]
    public function delete(string $type, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $kind = YouthDocumentType::from($type);
        $this->templates->remove($kind);
        $this->addFlash('success', sprintf('Modèle « %s » retiré : le modèle par défaut est de nouveau proposé.', $kind->label()));

        return $this->redirectToRoute('admin_youth_document_templates');
    }

    /** Le modèle actuellement proposé : le fichier déposé, sinon le modèle par défaut (Cerfa ou PDF vierge du club). */
    #[Route('/admin/youth-document-templates/{type}/file', name: 'admin_youth_document_template_file', methods: ['GET'], requirements: ['type' => self::TYPE_REQUIREMENT])]
    public function preview(string $type): Response
    {
        $kind = YouthDocumentType::from($type);
        $uploaded = $this->templates->response($kind);
        if ($uploaded !== null) {
            return $uploaded;
        }
        if ($kind->officialTemplateUrl() !== null) {
            return $this->redirect($kind->officialTemplateUrl());
        }

        $response = new Response($this->documents->renderGoHomeAloneTemplate(null));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, 'autorisation-rentrer-seul.pdf'));

        return $response;
    }

    private function validateCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('youth_document_template', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }
}
