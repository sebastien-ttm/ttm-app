<?php

namespace App\Controller\Api;

use App\Entity\TrainingGroup;
use App\Entity\TrainingGroupAttachment;
use App\Entity\TrainingGroupComment;
use App\Entity\TrainingGroupPosition;
use App\Entity\User;
use App\Enum\Sport;
use App\Repository\TrainingGroupAttachmentRepository;
use App\Repository\TrainingGroupCommentRepository;
use App\Repository\TrainingGroupPositionRepository;
use App\Repository\TrainingGroupRepository;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\Training\TrainingRegistrationService;
use App\Service\Training\WeeklyScheduleService;
use App\Service\TrainingGroup\TrainingGroupFileService;
use App\Service\TrainingGroup\TrainingGroupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Groupes d'entraînement (voir TrainingGroup).
 *
 *  - Encadrants / entraîneurs (espace Staff) : créent un groupe adossé à une séance de vélo ou de course à pied à venir
 *    (un groupe par encadrant et par séance, actif jusqu'à 8 h après le début de la séance ou jusqu'à ce qu'un encadrant le
 *    supprime), avec un nombre maximal d'adhérents, une
 *    allure du club (1 à 5) et une vitesse ; ils le partagent avec d'autres encadrants, y ajoutent des adhérents, une
 *    trace GPX, des informations, des commentaires (éventuellement avec un point GPS) et partagent leur position.
 *  - Adhérents : choisissent un groupe depuis le détail du créneau, tant que le groupe est actif (même une fois la séance
 *    commencée) et dans la limite des places (un seul groupe par séance) ; ils voient ensuite le groupe (onglet
 *    Entraînement), ses informations, ses encadrants, les autres membres, téléchargent la trace GPX, écrivent des
 *    commentaires et partagent leur position à leur demande, comme les encadrants.
 *
 * Données personnelles : le numéro de leurs parents et le contact d'urgence des adhérents ne sont lus que par les
 * encadrants du groupe. Les adhérents voient le téléphone des autres membres (appel / WhatsApp), sauf celui d'un profil
 * Jeune ; une position n'est visible que des encadrants et des adhérents du groupe.
 */
#[IsGranted('ROLE_USER')]
class TrainingGroupController extends AbstractController
{
    use StaffOnlyTrait;
    use StaffLiveStateTrait;

    /**
     * Les encadrants et les adhérents du groupe partagent leur position à la demande (un appui sur la carte), pas en
     * continu : une position reste affichée, avec son âge, pendant toute la vie du groupe (8 h après le début de la séance).
     */
    private const POSITION_FRESH_MINUTES = 8 * 60;
    private const COMMENT_LIMIT = 100;
    private const GPX_MAX_BYTES = 5_000_000;
    private const GPX_MAX_FILES = 5;

    public function __construct(
        private readonly TrainingGroupService $service,
        private readonly TrainingGroupFileService $files,
        private readonly TrainingGroupRepository $groups,
        private readonly TrainingGroupCommentRepository $comments,
        private readonly TrainingGroupAttachmentRepository $attachments,
        private readonly TrainingGroupPositionRepository $positions,
        private readonly UserRepository $users,
        private readonly AvatarService $avatars,
        private readonly EntityManagerInterface $em,
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingRegistrationService $registrations,
        private readonly string $publicUrl,
    ) {
    }

    // ============================================================
    //  Consultation (encadrants et adhérents du groupe)
    // ============================================================

    /**
     * Mes groupes actifs (comme encadrant ou comme adhérent). Pour un encadrant / entraîneur : `canCreate` et les
     * séances (vélo, course à pied, à venir) auxquelles il peut adosser un nouveau groupe — celles où il n'a pas déjà le
     * sien.
     */
    #[Route('/api/training-groups/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $now = new \DateTimeImmutable();
        $mine = $this->groups->findActiveFor($viewer, $now);
        $canCreate = $this->isStaff($viewer);

        return new JsonResponse([
            'data' => array_map(fn (TrainingGroup $g) => $this->summary($g, $viewer), $mine),
            'canCreate' => $canCreate,
            'sessions' => $canCreate ? $this->creatableSessions($viewer, $now) : [],
        ]);
    }

    /**
     * Encadrants / entraîneurs pouvant être ajoutés comme co-encadrants. `?occ=` (clé de la séance du groupe) : pour
     * chacun, `activeGroupId` est le groupe qu'il gère déjà SUR CETTE SÉANCE (un encadrant n'en a qu'un par séance).
     */
    #[Route('/api/training-groups/staff-candidates', methods: ['GET'])]
    public function staffCandidates(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessStaff($viewer);
        $now = new \DateTimeImmutable();

        // Groupe de chaque encadrant sur la séance demandée.
        $onSession = [];
        $occ = (string) $request->query->get('occ', '');
        if ($occ !== '') {
            foreach ($this->groups->findActiveAttached($now) as $g) {
                if ($g->occurrenceKey() === $occ) {
                    foreach ($g->getCoaches() as $coach) {
                        $onSession[$coach->getId()] = $g->getId();
                    }
                }
            }
        }

        $out = [];
        $seen = [];
        foreach (array_merge($this->users->findCoaches(), $this->users->findEncadrants()) as $u) {
            if ($u->getId() === $viewer->getId() || isset($seen[$u->getId()])) {
                continue;
            }
            $seen[$u->getId()] = true;
            $out[] = [
                'id' => $u->getId(),
                'nom' => $u->getNom(),
                'prenom' => $u->getPrenom(),
                'avatarUrl' => $this->avatars->urlFor($u),
                // Groupe que cet encadrant gère déjà sur la séance demandée (null sans `occ`).
                'activeGroupId' => $onSession[$u->getId()] ?? null,
            ];
        }
        usort($out, static fn (array $a, array $b) => [mb_strtolower($a['nom']), mb_strtolower($a['prenom'])] <=> [mb_strtolower($b['nom']), mb_strtolower($b['prenom'])]);

        return new JsonResponse(['data' => $out]);
    }

    /**
     * Détail d'un groupe : état complet, redemandé toutes les quelques secondes par l'appli (`?v=` : voir
     * StaffLiveStateTrait — `{unchanged: true}` si rien n'a changé).
     */
    #[Route('/api/training-groups/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        // Encadrant ou adhérent du groupe ; à défaut, encadrant d'un autre groupe de la même séance (lecture seule).
        $group = $this->groupFor($id, $viewer) ?? $this->siblingGroupFor($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }

        return $this->liveState($request, $this->detailState($group, $viewer));
    }

    /** Téléchargement authentifié d'une trace (jeton en query `bearer`, comme les autres pièces jointes). */
    #[Route('/api/training-groups/attachments/{id}/file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id): BinaryFileResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $att = $this->attachments->find($id);
        $group = $att?->getTrainingGroup();
        if ($att === null || $group === null || !$group->isActive()
            || !($group->hasCoach($viewer) || $group->hasMember($viewer) || $this->service->coachesOnSameSession($group, $viewer))) {
            throw $this->createNotFoundException();
        }
        $response = $this->files->fileResponse($att);
        if ($response === null) {
            throw $this->createNotFoundException();
        }

        return $response;
    }

    // ============================================================
    //  Choix d'un groupe depuis le créneau (adhérents)
    // ============================================================

    /**
     * Les groupes adossés à un créneau, pour que les adhérents en choisissent un depuis le détail du créneau.
     *
     * GET /api/training-groups/for-slot?week=YYYY-MM-DD&templateId=12  (ou &slotId=5)
     *
     * Réponse : { groups: [...], closed, canChoose, myGroupId }. `closed` : les groupes de la séance sont terminés (le
     * choix reste possible une fois la séance commencée, tant que le groupe est actif). Un créneau que le viewer ne voit
     * pas (audience, stage) répond 404, comme le planning.
     */
    #[Route('/api/training-groups/for-slot', methods: ['GET'])]
    public function forSlot(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $target = $this->parseSlotTarget($request->query->all());
        if ($target instanceof JsonResponse) {
            return $target;
        }
        [$monday, $templateId, $slotId] = $target;

        $payload = $this->slotGroupsPayload($viewer, $monday, $templateId, $slotId);

        return $payload === null ? $this->notFound('Créneau introuvable.') : new JsonResponse($payload);
    }

    /**
     * L'adhérent choisit ce groupe pour la séance (même commencée, tant que le groupe est actif) : il quitte celui qu'il
     * avait choisi pour la même séance. Refus (complet, groupe terminé) : 409 portant `code` (full | closed) et le message
     * à afficher.
     */
    #[Route('/api/training-groups/{id}/membership', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function join(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->groups->find($id);
        if ($group === null || !$group->isActive() || !$group->isAttached() || !$this->canSeeSlot($group, $viewer)) {
            return $this->notFound();
        }

        $refusal = $this->service->join($group, $viewer);
        if ($refusal !== null) {
            return new JsonResponse(
                ['error' => $refusal['message'], 'code' => $refusal['code']] + $this->slotGroupsFor($group, $viewer),
                $refusal['status'],
            );
        }

        return new JsonResponse($this->slotGroupsFor($group, $viewer));
    }

    /** L'adhérent quitte son groupe. */
    #[Route('/api/training-groups/{id}/membership', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function leaveMembership(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->groups->find($id);
        if ($group === null || !$group->isActive() || !$group->isAttached()) {
            return $this->notFound();
        }

        $refusal = $this->service->leaveAsMember($group, $viewer);
        if ($refusal !== null) {
            return new JsonResponse(
                ['error' => $refusal['message'], 'code' => $refusal['code']] + $this->slotGroupsFor($group, $viewer),
                $refusal['status'],
            );
        }

        return new JsonResponse($this->slotGroupsFor($group, $viewer));
    }

    // ============================================================
    //  Gestion (encadrants)
    // ============================================================

    /**
     * Crée le groupe de l'encadrant, adossé à une séance de vélo ou de course à pied à venir.
     *
     * Body : { slot: { week, templateId? | slotId? }, capacity, paceLevel?, speed?, name?, info?, memberIds: int[],
     * coachIds?: int[] }. Un encadrant n'a qu'un groupe par séance ; un adhérent n'est que dans un groupe par séance.
     */
    #[Route('/api/training-groups', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessStaff($viewer);
        $now = new \DateTimeImmutable();

        $p = $this->payload($request);
        $name = is_string($p['name'] ?? null) ? trim($p['name']) : '';
        $info = is_string($p['info'] ?? null) ? trim($p['info']) : '';
        if (mb_strlen($name) > TrainingGroup::NAME_MAX) {
            return $this->invalid(sprintf('Nom trop long (%d caractères max).', TrainingGroup::NAME_MAX));
        }
        if (mb_strlen($info) > TrainingGroup::INFO_MAX) {
            return $this->invalid(sprintf('Informations trop longues (%d caractères max).', TrainingGroup::INFO_MAX));
        }

        // Séance : vélo ou course à pied, en cours ou à venir.
        $slotInput = is_array($p['slot'] ?? null) ? $p['slot'] : [];
        $target = $this->parseSlotTarget($slotInput);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        [$monday, $templateId, $slotId] = $target;
        $session = $this->service->findEligibleSession($monday, $templateId, $slotId, $now);
        if ($session === null) {
            return $this->invalid('Choisissez une séance de vélo ou de course à pied à venir (non annulée).');
        }

        $capacity = $this->capacityFrom($p['capacity'] ?? null);
        if ($capacity === null) {
            return $this->invalid(sprintf('Indiquez le nombre maximal d\'adhérents (1 à %d).', TrainingGroup::MAX_MEMBERS));
        }
        $paceLevel = $this->paceLevelFrom($p['paceLevel'] ?? null);
        if ($paceLevel === false) {
            return $this->invalid('L\'allure doit être comprise entre 1 et 5.');
        }
        $speed = is_string($p['speed'] ?? null) ? trim($p['speed']) : '';
        if (mb_strlen($speed) > TrainingGroup::SPEED_MAX) {
            return $this->invalid(sprintf('Vitesse trop longue (%d caractères max).', TrainingGroup::SPEED_MAX));
        }

        // Un encadrant n'a qu'un groupe par séance (il peut en avoir sur d'autres séances).
        foreach ($this->groups->findActiveOnOccurrence($templateId, $slotId, $monday, $now) as $existing) {
            if ($existing->hasCoach($viewer)) {
                return new JsonResponse(['error' => 'Vous avez déjà un groupe pour cette séance.'], Response::HTTP_CONFLICT);
            }
        }

        [$coaches, $error] = $this->eligibleCoaches($this->intList($p['coachIds'] ?? []), $viewer, null, $session['occ']);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_CONFLICT);
        }
        $members = $this->eligibleMembers($this->intList($p['memberIds'] ?? []), $viewer, $coaches);
        if (count($members) > $capacity) {
            return $this->invalid(sprintf('Le groupe est limité à %d adhérent%s : retirez-en ou augmentez le nombre de places.', $capacity, $capacity > 1 ? 's' : ''));
        }
        $conflict = $this->conflictMessage($this->service->memberConflicts($members, $templateId, $slotId, $monday, null));
        if ($conflict !== null) {
            return new JsonResponse(['error' => $conflict], Response::HTTP_CONFLICT);
        }

        $group = $this->service->create($viewer, $name, $info, $coaches, $members, $session, $capacity, $paceLevel, $speed);

        return new JsonResponse($this->detailState($group, $viewer), Response::HTTP_CREATED);
    }

    /** Modifie le nom, les informations, le nombre de places, l'allure et / ou la vitesse. Body : { name?, info?, capacity?, paceLevel?, speed? }. */
    #[Route('/api/training-groups/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }

        $p = $this->payload($request);
        if (array_key_exists('name', $p)) {
            $name = is_string($p['name']) ? trim($p['name']) : '';
            if (mb_strlen($name) > TrainingGroup::NAME_MAX) {
                return $this->invalid(sprintf('Nom trop long (%d caractères max).', TrainingGroup::NAME_MAX));
            }
            $group->setName($name);
        }
        if (array_key_exists('info', $p)) {
            $info = is_string($p['info']) ? trim($p['info']) : '';
            if (mb_strlen($info) > TrainingGroup::INFO_MAX) {
                return $this->invalid(sprintf('Informations trop longues (%d caractères max).', TrainingGroup::INFO_MAX));
            }
            $group->setInfo($info);
        }
        if (array_key_exists('capacity', $p)) {
            $capacity = $this->capacityFrom($p['capacity']);
            if ($capacity === null) {
                return $this->invalid(sprintf('Indiquez le nombre maximal d\'adhérents (1 à %d).', TrainingGroup::MAX_MEMBERS));
            }
            $count = $group->getMembers()->count();
            if ($capacity < $count) {
                return $this->invalid(sprintf('Le groupe compte déjà %d adhérent%s : retirez-en avant de réduire le nombre de places.', $count, $count > 1 ? 's' : ''));
            }
            $group->setCapacity($capacity);
        }
        if (array_key_exists('paceLevel', $p)) {
            $level = $this->paceLevelFrom($p['paceLevel']);
            if ($level === false) {
                return $this->invalid('L\'allure doit être comprise entre 1 et 5.');
            }
            $group->setPaceLevel($level);
        }
        if (array_key_exists('speed', $p)) {
            $speed = is_string($p['speed']) ? trim($p['speed']) : '';
            if (mb_strlen($speed) > TrainingGroup::SPEED_MAX) {
                return $this->invalid(sprintf('Vitesse trop longue (%d caractères max).', TrainingGroup::SPEED_MAX));
            }
            $group->setSpeed($speed);
        }
        $this->em->flush();

        return new JsonResponse($this->detailState($group, $viewer));
    }

    /** Remplace la liste des adhérents du groupe. Body : { memberIds: int[] }. */
    #[Route('/api/training-groups/{id}/members', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function setMembers(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }
        $p = $this->payload($request);
        if (!is_array($p['memberIds'] ?? null)) {
            return $this->invalid('Liste d\'adhérents manquante.');
        }

        $members = $this->eligibleMembers($this->intList($p['memberIds']), $viewer, $group->getCoaches()->toArray());
        $limit = $group->getCapacity() ?? TrainingGroup::MAX_MEMBERS;
        if (count($members) > $limit) {
            return $this->invalid(sprintf('Le groupe est limité à %d adhérent%s : augmentez le nombre de places pour en ajouter.', $limit, $limit > 1 ? 's' : ''));
        }
        // Un adhérent n'est que dans un groupe par séance : on ne le « vole » pas à un autre groupe, on prévient.
        if ($group->isAttached()) {
            $added = array_values(array_filter($members, static fn (User $u) => !$group->hasMember($u)));
            $conflict = $this->conflictMessage($this->service->memberConflicts(
                $added,
                $group->getSlotTemplate()?->getId(),
                $group->getSlot()?->getId(),
                $group->getWeekStartsAt() ?? new \DateTimeImmutable('today'),
                $group,
            ));
            if ($conflict !== null) {
                return new JsonResponse(['error' => $conflict], Response::HTTP_CONFLICT);
            }
        }
        $this->service->setMembers($group, $members, $viewer);

        return new JsonResponse($this->detailState($group, $viewer));
    }

    /** Remplace la liste des AUTRES encadrants (celui qui agit reste encadrant). Body : { coachIds: int[] }. */
    #[Route('/api/training-groups/{id}/coaches', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function setCoaches(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }
        $p = $this->payload($request);
        if (!is_array($p['coachIds'] ?? null)) {
            return $this->invalid('Liste d\'encadrants manquante.');
        }

        [$coaches, $error] = $this->eligibleCoaches($this->intList($p['coachIds']), $viewer, $group, $group->occurrenceKey());
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_CONFLICT);
        }
        $this->service->setCoaches($group, $coaches, $viewer);
        // Un nouvel encadrant ne peut pas rester adhérent du même groupe.
        foreach ($coaches as $coach) {
            $group->removeMember($coach);
        }
        $this->em->flush();

        return new JsonResponse($this->detailState($group, $viewer));
    }

    /** Un encadrant quitte le groupe (le groupe se termine s'il était le dernier). */
    #[Route('/api/training-groups/{id}/leave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function leave(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }

        $group->removeCoach($viewer);
        $position = $this->positions->findOneFor($group, $viewer);
        if ($position !== null) {
            $this->em->remove($position);
        }
        if ($group->getCoaches()->isEmpty()) {
            $group->close();
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** Supprime le groupe pour tout le monde (il disparaît tout de suite ; ses données sont purgées une semaine plus tard). */
    #[Route('/api/training-groups/{id}/close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }
        $group->close();
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Ajoute un commentaire : un texte et / ou un point GPS. Body : { content?, latitude?, longitude? }. Les encadrants et
     * les adhérents du groupe peuvent commenter.
     */
    #[Route('/api/training-groups/{id}/comments', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addComment(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->groupFor($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }

        $p = $this->payload($request);
        $content = is_string($p['content'] ?? null) ? trim($p['content']) : '';
        if (mb_strlen($content) > TrainingGroupComment::CONTENT_MAX) {
            return $this->invalid(sprintf('Commentaire trop long (%d caractères max).', TrainingGroupComment::CONTENT_MAX));
        }
        $latitude = $p['latitude'] ?? null;
        $longitude = $p['longitude'] ?? null;
        $hasPoint = $latitude !== null || $longitude !== null;
        if ($hasPoint && !self::validPoint($latitude, $longitude)) {
            return $this->invalid('Point GPS invalide.');
        }
        if ($content === '' && !$hasPoint) {
            return $this->invalid('Écrivez un commentaire ou joignez un point GPS.');
        }

        $comment = new TrainingGroupComment(
            $group,
            $viewer,
            $content,
            $hasPoint ? (float) $latitude : null,
            $hasPoint ? (float) $longitude : null,
        );
        $this->em->persist($comment);
        $this->em->flush();

        return new JsonResponse($this->commentItem($comment, $viewer, $group->hasCoach($viewer)), Response::HTTP_CREATED);
    }

    /** Supprime un de MES commentaires ; un encadrant du groupe peut supprimer ceux des autres (modération). */
    #[Route('/api/training-groups/{id}/comments/{commentId}', methods: ['DELETE'], requirements: ['id' => '\d+', 'commentId' => '\d+'])]
    public function deleteComment(int $id, int $commentId): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->groupFor($id, $viewer);
        $comment = $group === null ? null : $this->comments->find($commentId);
        if ($group === null || $comment === null || $comment->getTrainingGroup()->getId() !== $group->getId()
            || !($comment->getAuthor()->getId() === $viewer->getId() || $group->hasCoach($viewer))) {
            return $this->notFound('Commentaire introuvable.');
        }
        $this->em->remove($comment);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** Ajoute une trace GPX (multipart, champ « file »). */
    #[Route('/api/training-groups/{id}/attachments', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addAttachment(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->invalid('Aucun fichier reçu.');
        }
        if (!$file->isValid()) {
            return $this->invalid('Le fichier n\'a pas pu être reçu (trop volumineux ?).');
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'gpx') {
            return $this->invalid('Seules les traces au format .gpx sont acceptées.');
        }
        if ((int) $file->getSize() > self::GPX_MAX_BYTES) {
            return $this->invalid('Fichier trop volumineux (5 Mo max).');
        }
        $head = (string) file_get_contents($file->getPathname(), false, null, 0, 4096);
        if (!str_contains(strtolower($head), '<gpx')) {
            return $this->invalid('Ce fichier ne ressemble pas à une trace GPX.');
        }
        if (count($this->attachments->findByGroup($group)) >= self::GPX_MAX_FILES) {
            return $this->invalid(sprintf('%d traces au plus par groupe : supprimez-en une d\'abord.', self::GPX_MAX_FILES));
        }

        try {
            $attachment = $this->files->upload($group, $viewer, $file);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $this->em->flush();

        return new JsonResponse($this->attachmentItem($attachment), Response::HTTP_CREATED);
    }

    #[Route('/api/training-groups/{id}/attachments/{attachmentId}', methods: ['DELETE'], requirements: ['id' => '\d+', 'attachmentId' => '\d+'])]
    public function deleteAttachment(int $id, int $attachmentId): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        $attachment = $group === null ? null : $this->attachments->find($attachmentId);
        if ($group === null || $attachment === null || $attachment->getTrainingGroup()->getId() !== $group->getId()) {
            return $this->notFound('Fichier introuvable.');
        }
        $this->files->remove($attachment);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Partage MA position (encadrant ou adhérent du groupe), à ma demande. Body : { latitude, longitude, accuracy? }.
     */
    #[Route('/api/training-groups/{id}/position', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function setPosition(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->groupFor($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }

        $p = $this->payload($request);
        if (!self::validPoint($p['latitude'] ?? null, $p['longitude'] ?? null)) {
            return $this->invalid('Position invalide.');
        }
        $accuracy = isset($p['accuracy']) && is_numeric($p['accuracy']) ? max(0, (int) round((float) $p['accuracy'])) : null;

        $position = $this->positions->findOneFor($group, $viewer);
        if ($position === null) {
            $this->em->persist(new TrainingGroupPosition($group, $viewer, (float) $p['latitude'], (float) $p['longitude'], $accuracy));
        } else {
            $position->moveTo((float) $p['latitude'], (float) $p['longitude'], $accuracy);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** Arrête le partage de MA position. */
    #[Route('/api/training-groups/{id}/position', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function stopPosition(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->groupFor($id, $viewer);
        if ($group === null) {
            return $this->notFound();
        }
        $position = $this->positions->findOneFor($group, $viewer);
        if ($position !== null) {
            $this->em->remove($position);
            $this->em->flush();
        }

        return new JsonResponse(['ok' => true]);
    }

    // ============================================================
    //  Aides
    // ============================================================

    private function isStaff(User $user): bool
    {
        return $user->isEntraineur() || $user->isEncadrant();
    }

    /** Groupe actif dont l'utilisateur est encadrant ou adhérent. */
    private function groupFor(int $id, User $viewer): ?TrainingGroup
    {
        $group = $this->groups->find($id);

        return $group !== null && $group->isActive() && ($group->hasCoach($viewer) || $group->hasMember($viewer)) ? $group : null;
    }

    /**
     * Groupe actif d'une séance sur laquelle l'utilisateur encadre un AUTRE groupe : les encadrants d'une même séance ont
     * une vue en lecture seule sur les groupes des autres (infos, adhérents, trace, commentaires, positions).
     */
    private function siblingGroupFor(int $id, User $viewer): ?TrainingGroup
    {
        $group = $this->groups->find($id);

        return $group !== null && $group->isActive() && $this->service->coachesOnSameSession($group, $viewer) ? $group : null;
    }

    /** Groupe actif dont l'utilisateur est encadrant. */
    private function coachedGroup(int $id, User $viewer): ?TrainingGroup
    {
        $group = $this->groups->find($id);

        return $group !== null && $group->isActive() && $group->hasCoach($viewer) ? $group : null;
    }

    private function notFound(string $message = 'Groupe introuvable ou terminé.'): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_NOT_FOUND);
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /** @return list<int> */
    private function intList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function validPoint(mixed $latitude, mixed $longitude): bool
    {
        return is_numeric($latitude) && is_numeric($longitude)
            && abs((float) $latitude) <= 90.0 && abs((float) $longitude) <= 180.0;
    }

    /**
     * Adhérents actifs choisis, hors encadrants du groupe et hors l'encadrant qui agit.
     *
     * @param list<int>  $ids
     * @param list<User> $coaches encadrants du groupe
     *
     * @return list<User>
     */
    private function eligibleMembers(array $ids, User $actor, array $coaches): array
    {
        if ($ids === []) {
            return [];
        }
        $excluded = [$actor->getId()];
        foreach ($coaches as $coach) {
            $excluded[] = $coach->getId();
        }

        $members = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            if ($user->isActive() && $user->isAdherent() && !in_array($user->getId(), $excluded, true)) {
                $members[] = $user;
            }
        }

        return $members;
    }

    /**
     * Encadrants / entraîneurs actifs choisis comme co-encadrants (hors l'encadrant qui agit). Chacun ne peut gérer qu'un
     * seul groupe par séance (`$occ` : clé de la séance) : s'il en a déjà un autre sur la même séance, message d'erreur.
     *
     * @param list<int> $ids
     *
     * @return array{0: list<User>, 1: ?string}
     */
    private function eligibleCoaches(array $ids, User $actor, ?TrainingGroup $current, ?string $occ): array
    {
        if ($ids === []) {
            return [[], null];
        }

        // Encadrants qui gèrent déjà un (autre) groupe sur cette séance.
        $busy = [];
        if ($occ !== null) {
            foreach ($this->groups->findActiveAttached(new \DateTimeImmutable()) as $other) {
                if ($other->occurrenceKey() === $occ && ($current === null || $other->getId() !== $current->getId())) {
                    foreach ($other->getCoaches() as $coach) {
                        $busy[$coach->getId()] = true;
                    }
                }
            }
        }

        $coaches = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            if (!$user->isActive() || !$this->isStaff($user) || $user->getId() === $actor->getId()) {
                continue;
            }
            if (isset($busy[$user->getId()])) {
                return [[], sprintf('%s gère déjà un groupe pour cette séance : un encadrant n\'en a qu\'un par séance.', $user->getFullName())];
            }
            $coaches[] = $user;
        }

        return [$coaches, null];
    }

    /**
     * Séance d'une ligne du planning, pour l'appli : de quoi l'identifier (clé, semaine, créneau), l'afficher et ouvrir
     * le détail du créneau.
     *
     * @param array{row: array<string, mixed>, title: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, monday: \DateTimeImmutable, occ: string} $session
     *
     * @return array<string, mixed>
     */
    private function sessionItem(array $session, int $groupCount): array
    {
        $row = $session['row'];
        $sport = Sport::tryFrom((string) $row['sport']);

        return [
            'occ' => $session['occ'],
            'week' => $session['monday']->format('Y-m-d'),
            'date' => $row['date'],
            'templateId' => $row['templateId'],
            'slotId' => $row['templateId'] === null ? $row['id'] : null,
            'startTime' => $row['startTime'],
            'startsAt' => $session['startsAt']->format(\DATE_ATOM),
            'endsAt' => $session['endsAt']->format(\DATE_ATOM),
            'title' => $session['title'],
            'location' => $row['location'],
            'sport' => $row['sport'],
            'sportLabel' => $sport?->label() ?? (string) $row['sport'],
            'sportIcon' => $sport?->icon() ?? '',
            // Groupes déjà créés pour cette séance (par d'autres encadrants).
            'groupCount' => $groupCount,
        ];
    }

    /**
     * Séances auxquelles l'encadrant peut adosser un nouveau groupe : vélo et course à pied à venir, hors celles où il a
     * déjà son groupe.
     *
     * @return list<array<string, mixed>>
     */
    private function creatableSessions(User $viewer, \DateTimeImmutable $now): array
    {
        $byOccurrence = [];
        $mineOn = [];
        foreach ($this->groups->findActiveAttached($now) as $g) {
            $key = (string) $g->occurrenceKey();
            $byOccurrence[$key] = ($byOccurrence[$key] ?? 0) + 1;
            if ($g->hasCoach($viewer)) {
                $mineOn[$key] = true;
            }
        }

        $out = [];
        foreach ($this->service->eligibleSessions($now) as $session) {
            if (!isset($mineOn[$session['occ']])) {
                $out[] = $this->sessionItem($session, $byOccurrence[$session['occ']] ?? 0);
            }
        }

        return $out;
    }

    /**
     * Désigne un créneau : `week` (une date de la semaine) + `templateId` (semaine type) ou `slotId` (occasionnel).
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: \DateTimeImmutable, 1: ?int, 2: ?int}|JsonResponse
     */
    private function parseSlotTarget(array $input): array|JsonResponse
    {
        $rawWeek = $input['week'] ?? null;
        if (!is_string($rawWeek) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawWeek) !== 1) {
            return $this->invalid('Paramètre "week" invalide (format attendu : AAAA-MM-JJ).');
        }
        try {
            $monday = WeeklyScheduleService::snapToMonday(new \DateTimeImmutable($rawWeek));
        } catch (\Exception) {
            return $this->invalid('Paramètre "week" invalide (format attendu : AAAA-MM-JJ).');
        }
        $templateId = isset($input['templateId']) && $input['templateId'] !== '' ? (int) $input['templateId'] : null;
        $slotId = isset($input['slotId']) && $input['slotId'] !== '' ? (int) $input['slotId'] : null;
        if ($templateId === null && $slotId === null) {
            return $this->invalid('Indiquez templateId ou slotId.');
        }

        return [$monday, $templateId, $slotId];
    }

    /** Nombre de places : un entier de 1 au maximum d'un groupe ; null si absent ou invalide. */
    private function capacityFrom(mixed $raw): ?int
    {
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) || $raw < 1 || $raw > TrainingGroup::MAX_MEMBERS) {
            return null;
        }

        return $raw;
    }

    /** Allure du club (1 à 5) : null si non précisée (absente, vide ou null), false si invalide. */
    private function paceLevelFrom(mixed $raw): int|false|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        return is_int($raw) && in_array($raw, TrainingGroup::PACE_LEVELS, true) ? $raw : false;
    }

    /**
     * Message de refus quand des adhérents sont déjà dans un autre groupe de la même séance ; null s'il n'y a pas de conflit.
     *
     * @param list<array{user: User, group: TrainingGroup}> $conflicts
     */
    private function conflictMessage(array $conflicts): ?string
    {
        if ($conflicts === []) {
            return null;
        }
        $parts = array_map(
            static fn (array $c) => sprintf('%s est déjà dans « %s »', $c['user']->getFullName(), $c['group']->displayName()),
            $conflicts,
        );

        return implode(' ; ', $parts).' pour cette séance : un adhérent n\'est que dans un groupe par séance.';
    }

    /**
     * Groupes d'une séance vus par `$viewer` ; null si le créneau n'existe pas ou n'est pas visible pour lui.
     *
     * @return array<string, mixed>|null
     */
    private function slotGroupsPayload(User $viewer, \DateTimeImmutable $monday, ?int $templateId, ?int $slotId): ?array
    {
        $row = $this->registrations->findRow($this->schedule->buildWeek($monday, $viewer), $templateId, $slotId);
        if ($row === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $groups = [];
        if (in_array($row['sport'], TrainingGroup::SPORTS, true) && !$row['isCancelled']) {
            $groups = $this->groups->findActiveOnOccurrence($templateId, $slotId, $monday, $now);
        }
        $myGroupId = null;
        $viewerCoaches = false;
        foreach ($groups as $g) {
            if ($g->hasMember($viewer)) {
                $myGroupId = $g->getId();
            }
            if ($g->hasCoach($viewer)) {
                $viewerCoaches = true;
            }
        }
        $start = new \DateTimeImmutable($row['date'].' '.$row['startTime']);

        return [
            'groups' => array_map(fn (TrainingGroup $g) => $this->slotGroupItem($g, $viewer, $viewerCoaches), $groups),
            // Les groupes de la séance sont terminés (8 h après son début) ; avant, on peut choisir son groupe même une
            // fois la séance commencée.
            'closed' => TrainingGroupService::expiryFor(['startsAt' => $start]) <= $now,
            'canChoose' => $viewer->isAdherent() && $viewer->isActive(),
            'myGroupId' => $myGroupId,
        ];
    }

    /**
     * Groupes de la séance du groupe donné : même contenu que forSlot(), pour rafraîchir l'écran du créneau après un
     * choix ou un refus.
     *
     * @return array<string, mixed>
     */
    private function slotGroupsFor(TrainingGroup $group, User $viewer): array
    {
        return $this->slotGroupsPayload($viewer, $this->groupMonday($group), $group->getSlotTemplate()?->getId(), $group->getSlot()?->getId())
            ?? ['groups' => [], 'closed' => true, 'canChoose' => false, 'myGroupId' => null];
    }

    /** Lundi de la semaine de la séance d'un groupe adossé à un créneau. */
    private function groupMonday(TrainingGroup $group): \DateTimeImmutable
    {
        $week = $group->getWeekStartsAt() ?? $group->getSlot()?->getWeekStartsAt() ?? new \DateTimeImmutable('today');

        return WeeklyScheduleService::snapToMonday($week);
    }

    /** Le viewer voit-il le créneau du groupe (audience, stage…) ? Sinon il ne peut ni voir ni choisir ce groupe. */
    private function canSeeSlot(TrainingGroup $group, User $viewer): bool
    {
        $rows = $this->schedule->buildWeek($this->groupMonday($group), $viewer);

        return $this->registrations->findRow($rows, $group->getSlotTemplate()?->getId(), $group->getSlot()?->getId()) !== null;
    }

    /**
     * Un groupe vu depuis le créneau : de quoi le choisir (allure, vitesse, places, encadrants, informations). Les
     * adhérents du groupe n'y figurent pas : on les voit une fois dans le groupe.
     *
     * @return array<string, mixed>
     */
    private function slotGroupItem(TrainingGroup $group, User $viewer, bool $viewerCoachesSession = false): array
    {
        return [
            'id' => $group->getId(),
            'name' => $group->displayName(),
            'paceLevel' => $group->getPaceLevel(),
            'speed' => $group->getSpeed(),
            'info' => $group->getInfo() === null ? null : mb_strimwidth($group->getInfo(), 0, 200, '…'),
            'capacity' => $group->getCapacity(),
            'memberCount' => $group->getMembers()->count(),
            'placesLeft' => $group->placesLeft(),
            'full' => $group->isFull(),
            'isMember' => $group->hasMember($viewer),
            'isCoach' => $group->hasCoach($viewer),
            // Le viewer peut ouvrir ce groupe : le sien, celui qu'il a choisi, ou — encadrant de la séance — celui d'un autre.
            'canView' => $group->hasCoach($viewer) || $group->hasMember($viewer) || $viewerCoachesSession,
            'coaches' => array_map(
                fn (User $u) => ['id' => $u->getId(), 'fullName' => $u->getFullName(), 'avatarUrl' => $this->avatars->urlFor($u)],
                array_values($group->getCoaches()->toArray()),
            ),
        ];
    }

    /**
     * Les AUTRES groupes actifs de la séance de `$group`, vus par un encadrant de cette séance : de quoi naviguer entre
     * les groupes (nom, allure, places, encadrants) et repérer les siens.
     *
     * @return list<array<string, mixed>>
     */
    private function siblingItems(TrainingGroup $group, User $viewer): array
    {
        $out = [];
        foreach ($this->service->groupsOnSameSession($group) as $other) {
            if ($other->getId() === $group->getId()) {
                continue;
            }
            $out[] = [
                'id' => $other->getId(),
                'name' => $other->displayName(),
                'paceLevel' => $other->getPaceLevel(),
                'speed' => $other->getSpeed(),
                'capacity' => $other->getCapacity(),
                'memberCount' => $other->getMembers()->count(),
                'isMine' => $other->hasCoach($viewer),
                'coaches' => array_map(
                    static fn (User $u) => $u->getFullName(),
                    array_values($other->getCoaches()->toArray()),
                ),
            ];
        }

        return $out;
    }

    private static function cleanPhone(?string $raw): ?string
    {
        $phone = $raw !== null ? trim($raw) : '';

        return $phone !== '' ? $phone : null;
    }

    /**
     * Sport, séance et places d'un groupe : ce que l'appli affiche en tête de groupe et sur les cartes.
     *
     * @return array<string, mixed>
     */
    private function sessionFacts(TrainingGroup $group): array
    {
        $sport = $group->getSport() !== null ? Sport::tryFrom($group->getSport()) : null;
        $startsAt = $group->getSessionStartsAt();

        return [
            // Séance à laquelle le groupe est adossé (null pour un ancien groupe) : de quoi ouvrir le détail du créneau.
            'occ' => $group->occurrenceKey(),
            'slot' => !$group->isAttached() || $startsAt === null ? null : [
                'week' => ($group->getWeekStartsAt() ?? $startsAt)->format('Y-m-d'),
                'date' => $startsAt->format('Y-m-d'),
                'time' => $startsAt->format('H:i'),
                'templateId' => $group->getSlotTemplate()?->getId(),
                'slotId' => $group->getSlot()?->getId(),
            ],
            'sport' => $group->getSport(),
            'sportLabel' => $sport?->label(),
            'sportIcon' => $sport?->icon(),
            'capacity' => $group->getCapacity(),
            'placesLeft' => $group->placesLeft(),
            'paceLevel' => $group->getPaceLevel(),
            'speed' => $group->getSpeed(),
            // La séance a commencé (information : les adhérents peuvent encore choisir ou quitter le groupe).
            'started' => $group->hasStarted(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(TrainingGroup $group, User $viewer): array
    {
        return $this->sessionFacts($group) + [
            'id' => $group->getId(),
            'name' => $group->displayName(),
            'expiresAt' => $group->getExpiresAt()->format(\DATE_ATOM),
            'isCoach' => $group->hasCoach($viewer),
            'isMember' => $group->hasMember($viewer),
            'sessionTitle' => $group->getSessionTitle(),
            'sessionStartsAt' => $group->getSessionStartsAt()?->format(\DATE_ATOM),
            'memberCount' => $group->getMembers()->count(),
            'coaches' => array_map(
                fn (User $u) => ['id' => $u->getId(), 'fullName' => $u->getFullName(), 'avatarUrl' => $this->avatars->urlFor($u)],
                array_values($group->getCoaches()->toArray()),
            ),
        ];
    }

    /**
     * État complet d'un groupe vu par `$viewer` (ordre stable : sert d'empreinte pour l'actualisation en direct).
     *
     * @return array<string, mixed>
     */
    private function detailState(TrainingGroup $group, User $viewer): array
    {
        $isCoach = $group->hasCoach($viewer);
        $isSibling = !$isCoach && !$group->hasMember($viewer);
        $byName = static fn (User $a, User $b): int => [mb_strtolower($a->getNom()), mb_strtolower($a->getPrenom()), $a->getId()]
            <=> [mb_strtolower($b->getNom()), mb_strtolower($b->getPrenom()), $b->getId()];

        $coaches = array_values($group->getCoaches()->toArray());
        usort($coaches, $byName);
        $members = array_values($group->getMembers()->toArray());
        usort($members, $byName);

        $since = (new \DateTimeImmutable())->modify('-'.self::POSITION_FRESH_MINUTES.' minutes');

        return $this->sessionFacts($group) + [
            'id' => $group->getId(),
            'name' => $group->displayName(),
            'rawName' => $group->getName(),
            'isMember' => $group->hasMember($viewer),
            // Vue en lecture seule d'un groupe d'une autre équipe d'encadrants de la même séance.
            'isSibling' => $isSibling,
            // Les autres groupes de la même séance, pour ceux qui encadrent la séance (navigation entre les groupes).
            'siblings' => $isCoach || $isSibling ? $this->siblingItems($group, $viewer) : [],
            'info' => $group->getInfo(),
            'createdAt' => $group->getCreatedAt()->format(\DATE_ATOM),
            'expiresAt' => $group->getExpiresAt()->format(\DATE_ATOM),
            'isCoach' => $isCoach,
            'session' => $group->getSessionTitle() === null ? null : [
                'title' => $group->getSessionTitle(),
                'startsAt' => $group->getSessionStartsAt()?->format(\DATE_ATOM),
            ],
            'coaches' => array_map(fn (User $u) => [
                'id' => $u->getId(),
                'nom' => $u->getNom(),
                'prenom' => $u->getPrenom(),
                'fullName' => $u->getFullName(),
                'avatarUrl' => $this->avatars->urlFor($u),
                'telephone' => self::cleanPhone($u->getTelephone()),
                'isMe' => $u->getId() === $viewer->getId(),
            ], $coaches),
            'members' => array_map(fn (User $u) => $this->memberItem($u, $viewer, $isCoach ? 'full' : ($isSibling ? 'none' : 'phone')), $members),
            'attachments' => array_map(fn (TrainingGroupAttachment $a) => $this->attachmentItem($a), $this->attachments->findByGroup($group)),
            'comments' => array_map(fn (TrainingGroupComment $c) => $this->commentItem($c, $viewer, $isCoach), $this->comments->findLatest($group, self::COMMENT_LIMIT)),
            'positions' => $this->positionItems($group, $isSibling, $since),
        ];
    }

    /**
     * Positions partagées dans le groupe : celles des encadrants et des adhérents (`isCoach` les distingue sur la carte).
     * Un encadrant d'une autre équipe de la séance (lecture seule) ne voit que celles des encadrants ; la position d'une
     * personne retirée du groupe depuis n'est plus affichée.
     *
     * @return list<array<string, mixed>>
     */
    private function positionItems(TrainingGroup $group, bool $viewerIsSibling, \DateTimeImmutable $since): array
    {
        $out = [];
        foreach ($this->positions->findFresh($group, $since) as $p) {
            $owner = $p->getUser();
            $ownerIsCoach = $group->hasCoach($owner);
            if (!$ownerIsCoach && (!$group->hasMember($owner) || $viewerIsSibling)) {
                continue;
            }
            $out[] = [
                'userId' => $owner->getId(),
                'fullName' => $owner->getFullName(),
                'isCoach' => $ownerIsCoach,
                'latitude' => $p->getLatitude(),
                'longitude' => $p->getLongitude(),
                'accuracy' => $p->getAccuracy(),
                'updatedAt' => $p->getUpdatedAt()->format(\DATE_ATOM),
            ];
        }

        return $out;
    }

    /**
     * Un adhérent du groupe, selon ce que `$viewer` peut en lire (`$contact`) :
     *  - `full` (encadrant du groupe) : ses numéros — le sien, à défaut celui d'un parent pour un enfant sans téléphone, et
     *    son contact d'urgence ;
     *  - `phone` (adhérent du groupe) : son propre numéro seulement, pour l'appeler ou lui écrire sur WhatsApp — jamais
     *    celui d'un profil Jeune, ni un numéro de parent ou d'urgence ;
     *  - `none` (encadrant d'un autre groupe de la séance) : son nom et sa photo.
     *
     * @return array<string, mixed>
     */
    private function memberItem(User $user, User $viewer, string $contact): array
    {
        $item = [
            'id' => $user->getId(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'avatarUrl' => $this->avatars->urlFor($user),
            'isMe' => $user->getId() === $viewer->getId(),
        ];
        if ($contact === 'none') {
            return $item;
        }
        if ($contact === 'phone') {
            return $item + ['telephone' => $user->isJeune() ? null : self::cleanPhone($user->getTelephone())];
        }

        $phone = self::cleanPhone($user->getTelephone());
        $phoneOf = null;
        if ($phone === null) {
            foreach ($user->getParents() as $parent) {
                $parentPhone = self::cleanPhone($parent->getTelephone());
                if ($parentPhone !== null) {
                    $phone = $parentPhone;
                    $phoneOf = $parent->getFullName();
                    break;
                }
            }
        }

        return $item + [
            'telephone' => $phone,
            'telephoneOf' => $phoneOf,
            'telephoneUrgence' => self::cleanPhone($user->getTelephoneUrgence()),
            // Profil Jeune : accès rapide à ses documents officiels (fiche sanitaire, autorisation de sortie).
            'isJeune' => $user->isJeune(),
        ];
    }

    /** @return array<string, mixed> */
    private function attachmentItem(TrainingGroupAttachment $attachment): array
    {
        return [
            'id' => $attachment->getId(),
            'name' => $attachment->getOriginalName(),
            'size' => $attachment->getSize(),
            'humanSize' => $attachment->getHumanSize(),
            'mimeType' => $attachment->getMimeType(),
            'url' => rtrim($this->publicUrl, '/').'/api/training-groups/attachments/'.$attachment->getId().'/file',
        ];
    }

    /**
     * Un commentaire. `canDelete` : l'auteur peut supprimer le sien, un encadrant du groupe ceux de tous (modération).
     *
     * @return array<string, mixed>
     */
    private function commentItem(TrainingGroupComment $comment, User $viewer, bool $viewerIsCoach): array
    {
        $author = $comment->getAuthor();

        return [
            'id' => $comment->getId(),
            'authorId' => $author->getId(),
            'authorName' => $author->getFullName(),
            'authorAvatarUrl' => $this->avatars->urlFor($author),
            'content' => $comment->getContent(),
            'latitude' => $comment->getLatitude(),
            'longitude' => $comment->getLongitude(),
            'createdAt' => $comment->getCreatedAt()->format(\DATE_ATOM),
            'canDelete' => $viewerIsCoach || $author->getId() === $viewer->getId(),
        ];
    }
}
