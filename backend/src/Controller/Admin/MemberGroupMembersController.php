<?php

namespace App\Controller\Admin;

use App\Entity\MemberGroup;
use App\Entity\MemberGroupMember;
use App\Entity\User;
use App\Repository\MemberGroupRepository;
use App\Repository\UserRepository;
use App\Service\MemberGroup\MemberGroupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écrans « membres d'un groupe » côté admin : liste triable + ajout
 * manuel via autocomplete sur les adhérents actifs + retrait ligne à
 * ligne. Le remplissage automatique (vote d'événement, réponse de
 * sondage) est géré par MemberGroupService.
 */
#[IsGranted('ROLE_ADMIN')]
class MemberGroupMembersController extends AbstractController
{
    public function __construct(
        private readonly MemberGroupRepository $groups,
        private readonly MemberGroupService $memberGroupService,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/member-groups/{id}/members', name: 'admin_member_group_members', methods: ['GET'])]
    public function index(int $id): Response
    {
        $group = $this->groups->find($id);
        if ($group === null) {
            throw $this->createNotFoundException();
        }
        $memberships = $group->getMemberships();
        // Adhérents actifs éligibles à ajouter (hors ceux déjà membres).
        $existingIds = [];
        foreach ($memberships as $m) {
            $existingIds[] = $m->getUser()->getId();
        }
        $available = $this->users->createQueryBuilder('u')
            ->andWhere('u.isActive = true');
        if ($existingIds !== []) {
            $available->andWhere('u.id NOT IN (:ids)')->setParameter('ids', $existingIds);
        }
        $availableUsers = $available
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/member_group_members.html.twig', [
            'group' => $group,
            'memberships' => $memberships,
            'availableUsers' => $availableUsers,
        ]);
    }

    #[Route('/admin/member-groups/{id}/members/add', name: 'admin_member_group_add_member', methods: ['POST'])]
    public function add(int $id, Request $request): RedirectResponse
    {
        $group = $this->groups->find($id);
        if ($group === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('member_group_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_member_group_members', ['id' => $id]);
        }
        $userId = (int) $request->request->get('userId');
        $user = $userId > 0 ? $this->users->find($userId) : null;
        if ($user === null) {
            $this->addFlash('danger', 'Adhérent introuvable.');
            return $this->redirectToRoute('admin_member_group_members', ['id' => $id]);
        }
        $added = $this->memberGroupService->addMember($group, $user, MemberGroupMember::SOURCE_MANUAL);
        $this->em->flush();
        $this->addFlash(
            $added ? 'success' : 'warning',
            $added ? sprintf('%s ajouté au groupe.', $user->getFullName())
                   : sprintf('%s appartenait déjà au groupe.', $user->getFullName()),
        );
        return $this->redirectToRoute('admin_member_group_members', ['id' => $id]);
    }

    #[Route('/admin/member-groups/{id}/members/{userId}/remove', name: 'admin_member_group_remove_member', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function remove(int $id, int $userId, Request $request): RedirectResponse
    {
        $group = $this->groups->find($id);
        if ($group === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('member_group_remove', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_member_group_members', ['id' => $id]);
        }
        $user = $this->users->find($userId);
        if ($user instanceof User) {
            $this->memberGroupService->removeMember($group, $user);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s retiré du groupe.', $user->getFullName()));
        }
        return $this->redirectToRoute('admin_member_group_members', ['id' => $id]);
    }
}
