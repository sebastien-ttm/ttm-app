<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\VolunteerApplication;
use App\Entity\VolunteerCampaign;
use App\Entity\VolunteerPole;
use App\Entity\VolunteerRole;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Repository\VolunteerApplicationRepository;
use App\Repository\VolunteerCampaignRepository;
use App\Repository\VolunteerPoleRepository;
use App\Repository\VolunteerRoleRepository;
use App\Service\Volunteer\VolunteerPresenter;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bénévolat d'événement (Breathman…) dans l'appli :
 *  - adhérent : consulter les fiches publiées, se positionner sur une ou plusieurs fiches, retirer une candidature
 *    en attente, suivre ses candidatures ;
 *  - responsable de pôle (ou administrateur) : voir les candidatures des fiches de ses pôles, valider ou décliner,
 *    affecter directement un adhérent.
 * Notifications push : aux responsables du pôle à chaque candidature, à l'adhérent à chaque décision.
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/volunteer')]
class VolunteerController extends AbstractController
{
    public function __construct(
        private readonly VolunteerCampaignRepository $campaigns,
        private readonly VolunteerPoleRepository $poles,
        private readonly VolunteerRoleRepository $roles,
        private readonly VolunteerApplicationRepository $applications,
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
        private readonly VolunteerPresenter $presenter,
        private readonly WebPushNotifier $webPush,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Campagne en cours (la plus proche des campagnes publiées), ou null s'il n'y en a pas. */
    #[Route('/current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $campaign = $this->campaigns->findVisible()[0] ?? null;
        if ($campaign === null) {
            return new JsonResponse(['campaign' => null]);
        }
        $managed = $this->managedPoles($viewer, $campaign);

        return new JsonResponse(['campaign' => $this->presenter->campaign(
            $campaign,
            $this->mineByRole($viewer, $campaign),
            $managed !== [],
            $this->applications->countPendingForPoles($managed),
        )]);
    }

    #[Route('/roles/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function role(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $role = $this->findVisibleRoleOr404($id);
        return new JsonResponse($this->presenter->detail($role, $this->applications->findOneByRoleAndUser($role, $viewer)));
    }

    /** Se positionner sur une fiche. JSON : { message? } (disponibilités, expérience…). */
    #[Route('/roles/{id}/application', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $role = $this->findVisibleRoleOr404($id);
        if (!$role->getCampaign()->isApplicationsOpen()) {
            return new JsonResponse(['error' => 'Les candidatures sont closes pour cet événement.'], Response::HTTP_CONFLICT);
        }
        $payload = json_decode($request->getContent(), true);
        $message = is_array($payload) && is_string($payload['message'] ?? null) ? $payload['message'] : null;
        if ($message !== null && mb_strlen(trim($message)) > VolunteerApplication::MESSAGE_MAX) {
            return new JsonResponse(['error' => sprintf('Message trop long (%d caractères max).', VolunteerApplication::MESSAGE_MAX)], Response::HTTP_BAD_REQUEST);
        }

        $application = $this->applications->findOneByRoleAndUser($role, $viewer);
        if ($application !== null && $application->getStatus() !== VolunteerApplication::STATUS_DECLINED) {
            return new JsonResponse(['error' => 'Vous êtes déjà positionné(e) sur cette fiche.'], Response::HTTP_CONFLICT);
        }
        if ($application === null) {
            $application = new VolunteerApplication($role, $viewer, $message);
            $role->getApplications()->add($application);
            $this->em->persist($application);
        } else {
            // Nouvelle candidature après un refus : elle repart en attente.
            $application->reopen($message);
        }
        $this->em->flush();

        $managerIds = $this->managerIds($role->getPole(), $viewer);
        $this->webPush->notifyUsers(
            $managerIds,
            sprintf('%s : nouvelle candidature', $role->getCampaign()->getName()),
            sprintf('%s se positionne sur « %s ».', $viewer->getFullName(), $role->getName()),
            '/volunteer/manage',
            'volunteer-manage',
        );

        return new JsonResponse($this->presenter->detail($role, $application), Response::HTTP_CREATED);
    }

    /** Retirer sa candidature tant qu'elle est en attente. */
    #[Route('/roles/{id}/application', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function withdraw(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $role = $this->findVisibleRoleOr404($id);
        $application = $this->applications->findOneByRoleAndUser($role, $viewer);
        if ($application === null) {
            throw $this->createNotFoundException('Aucune candidature sur cette fiche.');
        }
        if ($application->getStatus() !== VolunteerApplication::STATUS_PENDING) {
            return new JsonResponse(
                ['error' => 'Candidature déjà traitée : contactez les responsables du pôle pour la modifier.'],
                Response::HTTP_CONFLICT,
            );
        }
        $role->getApplications()->removeElement($application);
        $this->em->remove($application);
        $this->em->flush();

        return new JsonResponse($this->presenter->detail($role, null));
    }

    /** Mes candidatures sur la campagne. */
    #[Route('/campaigns/{id}/mine', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function mine(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $campaign = $this->findVisibleCampaignOr404($id);
        return new JsonResponse(['data' => array_map(
            fn (VolunteerApplication $a) => $this->presenter->application($a, false),
            $this->applications->findForUser($viewer, $campaign),
        )]);
    }

    /** Responsables de pôle : candidatures des fiches de leurs pôles (tous les pôles pour un administrateur). */
    #[Route('/campaigns/{id}/manage', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function manage(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $campaign = $this->findVisibleCampaignOr404($id);
        $managed = $this->managedPoles($viewer, $campaign);
        if ($managed === []) {
            throw $this->createAccessDeniedException('Réservé aux responsables de pôle.');
        }

        $roles = [];
        foreach ($managed as $pole) {
            foreach ($pole->getRoles() as $role) {
                $roles[] = $this->presenter->summary($role, null) + ['published' => $role->isPublished()];
            }
        }

        return new JsonResponse([
            'poles' => array_map(fn (VolunteerPole $p) => $this->presenter->pole($p), $managed),
            'roles' => $roles,
            'applications' => array_map(
                fn (VolunteerApplication $a) => $this->presenter->application($a, true),
                $this->applications->findForPoles($managed),
            ),
        ]);
    }

    /** Décision d'un responsable. JSON : { status: accepted | declined | pending }. */
    #[Route('/applications/{id}/decision', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $application = $this->applications->find($id);
        if ($application === null) {
            throw $this->createNotFoundException();
        }
        $this->denyUnlessManager($viewer, $application->getRole()->getPole());

        $payload = json_decode($request->getContent(), true);
        $status = is_array($payload) ? (string) ($payload['status'] ?? '') : '';
        if (!in_array($status, VolunteerApplication::STATUSES, true)) {
            return new JsonResponse(['error' => 'Décision invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $previous = $application->getStatus();
        $application->decide($status, $viewer);
        $this->em->flush();

        if ($status !== $previous && $status !== VolunteerApplication::STATUS_PENDING) {
            $role = $application->getRole();
            $this->webPush->notifyUsers(
                [(int) $application->getUser()->getId()],
                sprintf('%s : candidature %s', $role->getCampaign()->getName(), $status === VolunteerApplication::STATUS_ACCEPTED ? 'validée' : 'déclinée'),
                $status === VolunteerApplication::STATUS_ACCEPTED
                    ? sprintf('Bienvenue dans l\'équipe « %s » !', $role->getName())
                    : sprintf('Votre candidature sur « %s » n\'a pas été retenue. Merci pour votre proposition !', $role->getName()),
                '/volunteer/role/'.$role->getId(),
                'volunteer-role-'.$role->getId(),
            );
        }

        return new JsonResponse($this->presenter->application($application, true));
    }

    /** Affectation directe d'un adhérent par un responsable. JSON : { userId }. */
    #[Route('/roles/{id}/assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assign(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $role = $this->roles->find($id);
        if ($role === null) {
            throw $this->createNotFoundException();
        }
        $this->denyUnlessManager($viewer, $role->getPole());

        $payload = json_decode($request->getContent(), true);
        $user = is_array($payload) && isset($payload['userId']) ? $this->users->find((int) $payload['userId']) : null;
        if (!$user instanceof User || !$user->isActive()) {
            return new JsonResponse(['error' => 'Adhérent introuvable.'], Response::HTTP_BAD_REQUEST);
        }

        $application = $this->applications->findOneByRoleAndUser($role, $user);
        if ($application === null) {
            $application = new VolunteerApplication($role, $user);
            $role->getApplications()->add($application);
            $this->em->persist($application);
        }
        $alreadyAccepted = $application->getStatus() === VolunteerApplication::STATUS_ACCEPTED;
        $application->decide(VolunteerApplication::STATUS_ACCEPTED, $viewer);
        if (!$alreadyAccepted) {
            $application->setAssignedBy($viewer);
        }
        $this->em->flush();

        if (!$alreadyAccepted && $user->getId() !== $viewer->getId()) {
            $this->webPush->notifyUsers(
                [(int) $user->getId()],
                sprintf('%s : bienvenue dans l\'équipe', $role->getCampaign()->getName()),
                sprintf('%s vous a ajouté(e) sur « %s ».', $viewer->getFullName(), $role->getName()),
                '/volunteer/role/'.$role->getId(),
                'volunteer-role-'.$role->getId(),
            );
        }

        return new JsonResponse($this->presenter->application($application, true), Response::HTTP_CREATED);
    }

    /** Recherche d'adhérents pour l'affectation (responsables de pôle uniquement). ?q=texte */
    #[Route('/campaigns/{id}/members', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function members(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $campaign = $this->findVisibleCampaignOr404($id);
        if ($this->managedPoles($viewer, $campaign) === []) {
            throw $this->createAccessDeniedException('Réservé aux responsables de pôle.');
        }
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['data' => []]);
        }
        return new JsonResponse(['data' => array_map(
            static fn (User $u) => ['id' => $u->getId(), 'fullName' => $u->getFullName()],
            // Uniquement les adhérents de la saison en cours (tous les actifs à défaut de saison définie).
            $this->users->searchActiveAdherents($q, $this->seasons->findCurrent()),
        )]);
    }

    /** @return list<VolunteerPole> */
    private function managedPoles(User $viewer, VolunteerCampaign $campaign): array
    {
        if ($viewer->isAdmin()) {
            return array_values($campaign->getPoles()->toArray());
        }
        return $this->poles->findManagedBy($viewer, $campaign);
    }

    private function denyUnlessManager(User $viewer, VolunteerPole $pole): void
    {
        if (!$viewer->isAdmin() && !$pole->isManagedBy($viewer)) {
            throw $this->createAccessDeniedException('Réservé aux responsables de ce pôle.');
        }
    }

    /** @return list<int> responsables du pôle, sauf l'auteur de l'action */
    private function managerIds(VolunteerPole $pole, User $except): array
    {
        $ids = [];
        foreach ($pole->getManagers() as $m) {
            if ($m->getId() !== $except->getId() && $m->isActive()) {
                $ids[] = (int) $m->getId();
            }
        }
        return $ids;
    }

    /** @return array<int, VolunteerApplication> */
    private function mineByRole(User $viewer, VolunteerCampaign $campaign): array
    {
        $out = [];
        foreach ($this->applications->findForUser($viewer, $campaign) as $a) {
            $out[(int) $a->getRole()->getId()] = $a;
        }
        return $out;
    }

    private function findVisibleCampaignOr404(int $id): VolunteerCampaign
    {
        $campaign = $this->campaigns->find($id);
        if ($campaign === null || !$campaign->isPublished()) {
            throw $this->createNotFoundException('Événement introuvable.');
        }
        return $campaign;
    }

    private function findVisibleRoleOr404(int $id): VolunteerRole
    {
        $role = $this->roles->find($id);
        if ($role === null || !$role->isPublished() || !$role->getCampaign()->isPublished()) {
            throw $this->createNotFoundException('Fiche introuvable.');
        }
        return $role;
    }
}
