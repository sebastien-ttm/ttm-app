<?php

namespace App\Controller\Admin;

use App\Repository\PhotoUploadRepository;
use App\Service\Piwigo\PiwigoClient;
use App\Service\Piwigo\PiwigoException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Albums de la galerie Piwigo (« Photos du club ») : liste des albums
 * visibles dans l'appli et suppression d'un album avec ses photos.
 * Les albums vivent dans Piwigo (pas d'entité Doctrine) ; seules les
 * traces PhotoUpload de l'album sont supprimées côté base.
 */
#[IsGranted('ROLE_ADMIN')]
class PhotoAlbumAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    public function __construct(
        private readonly PiwigoClient $piwigo,
        private readonly PhotoUploadRepository $uploads,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/photo-albums', name: 'admin_photo_albums', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_photo_albums')) {
            return $r;
        }

        $albums = [];
        $error = null;
        if (!$this->piwigo->isConfigured()) {
            $error = 'La galerie Piwigo n\'est pas configurée (PIWIGO_* dans .env).';
        } else {
            try {
                $albums = $this->piwigo->listAlbums();
            } catch (PiwigoException $e) {
                $this->logger->error('Piwigo indisponible', ['error' => $e->getMessage()]);
                $error = 'Galerie Piwigo injoignable : '.$e->getMessage();
            }
        }

        return $this->render('admin/photo_albums.html.twig', [
            'albums' => $albums,
            'appCounts' => $this->uploads->countByAlbum(),
            'error' => $error,
        ]);
    }

    #[Route('/admin/photo-albums/{id}/delete', name: 'admin_photo_albums_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('photo_album_admin', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $name = '#'.$id;
        try {
            foreach ($this->piwigo->listAlbums() as $a) {
                if ($a['id'] === $id) {
                    $name = $a['name'];
                    break;
                }
            }
            $imageIds = $this->piwigo->deleteAlbum($id);
        } catch (PiwigoException $e) {
            $this->logger->error('Piwigo : suppression album impossible', ['albumId' => $id, 'error' => $e->getMessage()]);
            $this->addFlash('danger', sprintf('Suppression de l\'album « %s » impossible : %s', $name, $e->getMessage()));
            return $this->redirectToRoute('admin_photo_albums');
        }

        $this->uploads->deleteByAlbum($id);
        $this->logger->info('Album photo supprimé depuis l\'admin', [
            'albumId' => $id,
            'name' => $name,
            'images' => count($imageIds),
            'by' => $this->getUser()?->getUserIdentifier(),
        ]);
        $this->addFlash('success', sprintf('Album « %s » supprimé (%d photo%s).', $name, count($imageIds), count($imageIds) > 1 ? 's' : ''));

        return $this->redirectToRoute('admin_photo_albums');
    }
}
