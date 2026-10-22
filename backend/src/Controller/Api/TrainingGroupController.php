<?php

namespace App\Controller\Api;

use App\Entity\TrainingGroup;
use App\Entity\TrainingGroupAttachment;
use App\Entity\TrainingGroupComment;
use App\Entity\TrainingGroupPosition;
use App\Entity\User;
use App\Repository\TrainingGroupAttachmentRepository;
use App\Repository\TrainingGroupCommentRepository;
use App\Repository\TrainingGroupPositionRepository;
use App\Repository\TrainingGroupRepository;
use App\Repository\UserRepository;
use App\Service\AvatarService;
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
 * Groupes d'entraînement « à la volée » (voir TrainingGroup).
 *
 *  - Encadrants / entraîneurs (espace Staff) : créent leur groupe juste avant une séance (un seul groupe actif à la fois,
 *    valable jusqu'au prochain créneau), le partagent avec d'autres encadrants, y ajoutent des adhérents, une trace GPX,
 *    des informations, des commentaires (éventuellement avec un point GPS) et partagent leur position en direct.
 *  - Adhérents ajoutés : voient le groupe (onglet Entraînement), ses informations, ses encadrants, les autres membres,
 *    téléchargent la trace GPX et suivent la position des encadrants.
 *
 * Données personnelles : le téléphone, le numéro de leurs parents et le contact d'urgence des adhérents ne sont lus que
 * par les encadrants du groupe ; les adhérents voient seulement le nom et la photo des autres membres.
 */
#[IsGranted('ROLE_USER')]
class TrainingGroupController extends AbstractController
{
    use StaffOnlyTrait;
    use StaffLiveStateTrait;

    /** Une position plus ancienne est celle d'un partage abandonné : elle n'est plus montrée. */
    private const POSITION_FRESH_MINUTES = 30;
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
        private readonly string $publicUrl,
    ) {
    }

    // ============================================================
    //  Consultation (encadrants et adhérents du groupe)
    // ============================================================

    /**
     * Mes groupes actifs (comme encadrant ou comme adhérent). Pour un encadrant / entraîneur sans groupe : `canCreate`
     * et l'aperçu de la séance de référence et de la fin de validité d'un nouveau groupe.
     */
    #[Route('/api/training-groups/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $now = new \DateTimeImmutable();
        $mine = $this->groups->findActiveFor($viewer, $now);

        $coaching = false;
        foreach ($mine as $g) {
            if ($g->hasCoach($viewer)) {
                $coaching = true;
            }
        }
        $canCreate = $this->isStaff($viewer) && !$coaching;

        return new JsonResponse([
            'data' => array_map(fn (TrainingGroup $g) => $this->summary($g, $viewer), $mine),
            'canCreate' => $canCreate,
            'preview' => $canCreate ? $this->planItem($this->service->plan($now)) : null,
        ]);
    }

    /** Encadrants / entraîneurs pouvant être ajoutés comme co-encadrants (avec leur éventuel groupe actif). */
    #[Route('/api/training-groups/staff-candidates', methods: ['GET'])]
    public function staffCandidates(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessStaff($viewer);
        $now = new \DateTimeImmutable();

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
                // Groupe actif que cet encadrant gère déjà (il ne peut en avoir qu'un).
                'activeGroupId' => $this->groups->findActiveCoachedBy($u, $now)?->getId(),
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
        $group = $this->groupFor($id, $viewer);
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
        if ($att === null || $group === null || !$group->isActive() || !($group->hasCoach($viewer) || $group->hasMember($viewer))) {
            throw $this->createNotFoundException();
        }
        $response = $this->files->fileResponse($att);
        if ($response === null) {
            throw $this->createNotFoundException();
        }

        return $response;
    }

    // ============================================================
    //  Gestion (encadrants)
    // ============================================================

    /** Crée le groupe de l'encadrant. Body : { name?, info?, memberIds: int[], coachIds?: int[] }. */
    #[Route('/api/training-groups', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyUnlessStaff($viewer);
        $now = new \DateTimeImmutable();
        if ($this->groups->findActiveCoachedBy($viewer, $now) !== null) {
            return new JsonResponse(['error' => 'Vous avez déjà un groupe actif : terminez-le avant d\'en créer un autre.'], Response::HTTP_CONFLICT);
        }

        $p = $this->payload($request);
        $name = is_string($p['name'] ?? null) ? trim($p['name']) : '';
        $info = is_string($p['info'] ?? null) ? trim($p['info']) : '';
        if (mb_strlen($name) > TrainingGroup::NAME_MAX) {
            return $this->invalid(sprintf('Nom trop long (%d caractères max).', TrainingGroup::NAME_MAX));
        }
        if (mb_strlen($info) > TrainingGroup::INFO_MAX) {
            return $this->invalid(sprintf('Informations trop longues (%d caractères max).', TrainingGroup::INFO_MAX));
        }

        [$coaches, $error] = $this->eligibleCoaches($this->intList($p['coachIds'] ?? []), $viewer, null);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_CONFLICT);
        }
        $members = $this->eligibleMembers($this->intList($p['memberIds'] ?? []), $viewer, $coaches);
        if (count($members) > TrainingGroup::MAX_MEMBERS) {
            return $this->invalid(sprintf('Un groupe compte %d adhérents au plus.', TrainingGroup::MAX_MEMBERS));
        }

        $group = $this->service->create($viewer, $name, $info, $coaches, $members);

        return new JsonResponse($this->detailState($group, $viewer), Response::HTTP_CREATED);
    }

    /** Modifie le nom et / ou les informations. Body : { name?, info? }. */
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
        if (count($members) > TrainingGroup::MAX_MEMBERS) {
            return $this->invalid(sprintf('Un groupe compte %d adhérents au plus.', TrainingGroup::MAX_MEMBERS));
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

        [$coaches, $error] = $this->eligibleCoaches($this->intList($p['coachIds']), $viewer, $group);
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

    /** Termine le groupe pour tout le monde. */
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

    /** Ajoute un commentaire : un texte et / ou un point GPS. Body : { content?, latitude?, longitude? }. */
    #[Route('/api/training-groups/{id}/comments', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addComment(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
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

        return new JsonResponse($this->commentItem($comment, $viewer, true), Response::HTTP_CREATED);
    }

    /** Supprime un de MES commentaires. */
    #[Route('/api/training-groups/{id}/comments/{commentId}', methods: ['DELETE'], requirements: ['id' => '\d+', 'commentId' => '\d+'])]
    public function deleteComment(int $id, int $commentId): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
        $comment = $group === null ? null : $this->comments->find($commentId);
        if ($group === null || $comment === null || $comment->getTrainingGroup()->getId() !== $group->getId() || $comment->getAuthor()->getId() !== $viewer->getId()) {
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

    /** Met à jour MA position partagée en direct. Body : { latitude, longitude, accuracy? }. */
    #[Route('/api/training-groups/{id}/position', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function setPosition(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $group = $this->coachedGroup($id, $viewer);
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
        $group = $this->coachedGroup($id, $viewer);
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
     * seul groupe actif : s'il en a déjà un autre, message d'erreur.
     *
     * @param list<int> $ids
     *
     * @return array{0: list<User>, 1: ?string}
     */
    private function eligibleCoaches(array $ids, User $actor, ?TrainingGroup $current): array
    {
        if ($ids === []) {
            return [[], null];
        }
        $now = new \DateTimeImmutable();
        $coaches = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            if (!$user->isActive() || !$this->isStaff($user) || $user->getId() === $actor->getId()) {
                continue;
            }
            $other = $this->groups->findActiveCoachedBy($user, $now);
            if ($other !== null && ($current === null || $other->getId() !== $current->getId())) {
                return [[], sprintf('%s gère déjà un groupe actif : il doit d\'abord le terminer ou le quitter.', $user->getFullName())];
            }
            $coaches[] = $user;
        }

        return [$coaches, null];
    }

    private static function cleanPhone(?string $raw): ?string
    {
        $phone = $raw !== null ? trim($raw) : '';

        return $phone !== '' ? $phone : null;
    }

    /**
     * @param array{session: ?array{title: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}, expiresAt: \DateTimeImmutable} $plan
     *
     * @return array<string, mixed>
     */
    private function planItem(array $plan): array
    {
        return [
            'session' => $plan['session'] === null ? null : [
                'title' => $plan['session']['title'],
                'startsAt' => $plan['session']['startsAt']->format(\DATE_ATOM),
                'endsAt' => $plan['session']['endsAt']->format(\DATE_ATOM),
            ],
            'expiresAt' => $plan['expiresAt']->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(TrainingGroup $group, User $viewer): array
    {
        return [
            'id' => $group->getId(),
            'name' => $group->displayName(),
            'expiresAt' => $group->getExpiresAt()->format(\DATE_ATOM),
            'isCoach' => $group->hasCoach($viewer),
            'sessionTitle' => $group->getSessionTitle(),
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
        $byName = static fn (User $a, User $b): int => [mb_strtolower($a->getNom()), mb_strtolower($a->getPrenom()), $a->getId()]
            <=> [mb_strtolower($b->getNom()), mb_strtolower($b->getPrenom()), $b->getId()];

        $coaches = array_values($group->getCoaches()->toArray());
        usort($coaches, $byName);
        $members = array_values($group->getMembers()->toArray());
        usort($members, $byName);

        $since = (new \DateTimeImmutable())->modify('-'.self::POSITION_FRESH_MINUTES.' minutes');

        return [
            'id' => $group->getId(),
            'name' => $group->displayName(),
            'rawName' => $group->getName(),
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
            'members' => array_map(fn (User $u) => $this->memberItem($u, $isCoach), $members),
            'attachments' => array_map(fn (TrainingGroupAttachment $a) => $this->attachmentItem($a), $this->attachments->findByGroup($group)),
            'comments' => array_map(fn (TrainingGroupComment $c) => $this->commentItem($c, $viewer, $isCoach), $this->comments->findLatest($group, self::COMMENT_LIMIT)),
            'positions' => array_map(fn (TrainingGroupPosition $p) => [
                'userId' => $p->getUser()->getId(),
                'fullName' => $p->getUser()->getFullName(),
                'latitude' => $p->getLatitude(),
                'longitude' => $p->getLongitude(),
                'accuracy' => $p->getAccuracy(),
                'updatedAt' => $p->getUpdatedAt()->format(\DATE_ATOM),
            ], $this->positions->findFresh($group, $since)),
        ];
    }

    /**
     * Un adhérent du groupe. Les encadrants du groupe voient en plus ses numéros (le sien, à défaut celui d'un parent
     * pour un enfant sans téléphone, et son contact d'urgence) ; les autres membres n'ont que son nom et sa photo.
     *
     * @return array<string, mixed>
     */
    private function memberItem(User $user, bool $withContact): array
    {
        $item = [
            'id' => $user->getId(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'avatarUrl' => $this->avatars->urlFor($user),
        ];
        if (!$withContact) {
            return $item;
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

    /** @return array<string, mixed> */
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
            'canDelete' => $viewerIsCoach && $author->getId() === $viewer->getId(),
        ];
    }
}
