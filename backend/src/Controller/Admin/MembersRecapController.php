<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Trombinoscope admin des adhérents : liste rapide (nom, prénom, date
 * de naissance, photo) présentée en grille visuelle. Utile pour
 * repérer un adhérent quand on ne se souvient plus du nom, ou pour
 * imprimer un récap lors des inscriptions physiques.
 *
 * Distinct du CRUD Utilisateurs (édition profil) : ici c'est purement
 * de la consultation, format optimisé pour la lecture rapide.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class MembersRecapController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AvatarService $avatars,
    ) {
    }

    #[Route('/admin/adherents/recap', name: 'admin_members_recap')]
    public function index(): Response
    {
        $members = $this->users->findActiveAdherentsForRecap();

        // Construit la liste finale avec URL avatar résolue + année de
        // naissance pré-calculée (économise du Twig).
        $rows = array_map(function (User $u): array {
            return [
                'id' => $u->getId(),
                'nom' => $u->getNom(),
                'prenom' => $u->getPrenom(),
                'dateNaissance' => $u->getDateNaissance(),
                'categorieFFTri' => $u->getCategorieFFTri(),
                'avatarUrl' => $this->avatars->urlFor($u),
                'initials' => strtoupper(mb_substr($u->getPrenom(), 0, 1).mb_substr($u->getNom(), 0, 1)),
            ];
        }, $members);

        return $this->render('admin/members_recap.html.twig', [
            'members' => $rows,
            'total' => count($rows),
            'withAvatar' => count(array_filter($rows, fn ($r) => $r['avatarUrl'] !== null)),
        ]);
    }
}
