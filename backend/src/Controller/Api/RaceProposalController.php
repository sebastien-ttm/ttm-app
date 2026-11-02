<?php

namespace App\Controller\Api;

use App\Entity\RaceProposal;
use App\Entity\RaceProposalCourse;
use App\Entity\RaceProposalVote;
use App\Entity\User;
use App\Enum\RaceType;
use App\Repository\RaceProposalRepository;
use App\Repository\RaceProposalVoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Compétitions proposées (onglet Social) : n'importe quel adhérent propose une compétition — un événement d'un ou
 * plusieurs jours, avec une ou plusieurs épreuves — (capitaine ou non), les autres indiquent leur intérêt. Seules les
 * compétitions à venir ou en cours sont listées ; l'auteur peut modifier ou supprimer sa proposition.
 */
#[IsGranted('ROLE_USER')]
class RaceProposalController extends AbstractController
{
    public function __construct(
        private readonly RaceProposalRepository $proposals,
        private readonly RaceProposalVoteRepository $votes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/races', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        return new JsonResponse([
            'data' => array_map(fn (RaceProposal $r) => $this->serialize($r, $viewer), $this->proposals->findUpcoming()),
            'types' => $this->typeOptions(),
        ]);
    }

    #[Route('/api/races/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        return new JsonResponse($this->serialize($this->findOr404($id), $viewer));
    }

    /**
     * Création. JSON : name, raceDate (premier jour AAAA-MM-JJ), raceEndDate? (dernier jour AAAA-MM-JJ, vide = une
     * seule journée), url?, description?, captain, registrationDeadline? (capitaine : date limite de réponse
     * AAAA-MM-JJ), type, courses? (liste de { name, date?, details? }).
     */
    #[Route('/api/races', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $proposal = new RaceProposal($user);
        $error = $this->apply($proposal, $payload, true);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        // Courses saisies dès la création (facultatif) : tout est enregistré ensemble, ou rien.
        $courses = $payload['courses'] ?? [];
        if (!is_array($courses)) {
            return new JsonResponse(['error' => 'Liste des courses invalide.'], Response::HTTP_BAD_REQUEST);
        }
        foreach ($courses as $input) {
            $error = is_array($input) ? $this->addCourseFrom($proposal, $user, $input) : 'Course invalide.';
            if ($error !== null) {
                return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
            }
        }
        $this->em->persist($proposal);
        $this->em->flush();

        return new JsonResponse($this->serialize($proposal, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/races/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $proposal = $this->findOwnedOr404($id, $user);
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->apply($proposal, $payload, false);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $proposal->touchUpdatedAt();
        $this->em->flush();

        return new JsonResponse($this->serialize($proposal, $user));
    }

    #[Route('/api/races/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->em->remove($this->findOwnedOr404($id, $user));
        $this->em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Ajoute une course à la compétition (tout adhérent). JSON : name, date? (AAAA-MM-JJ, dans les dates de la
     * compétition), details? (distances, horaire…).
     */
    #[Route('/api/races/{id}/courses', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createCourse(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $proposal = $this->findOr404($id);
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->addCourseFrom($proposal, $user, $payload);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $this->em->flush();

        return new JsonResponse($this->serialize($proposal, $user), Response::HTTP_CREATED);
    }

    /**
     * Ajoute à la compétition la course décrite par `$input` (name, date?, details?) ; le flush reste à l'appelant.
     * Retourne un message d'erreur ou null.
     *
     * @param array<string, mixed> $input
     */
    private function addCourseFrom(RaceProposal $proposal, User $user, array $input): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return 'Indiquez le nom de la course.';
        }
        if (mb_strlen($name) > RaceProposalCourse::NAME_MAX) {
            return sprintf('Nom de course trop long (%d caractères max).', RaceProposalCourse::NAME_MAX);
        }

        $date = null;
        $raw = trim((string) ($input['date'] ?? ''));
        if ($raw !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($date === false || $date->format('Y-m-d') !== $raw) {
                return sprintf('Date de la course « %s » invalide.', $name);
            }
            if ($date < $proposal->getRaceDate() || $date > $proposal->getLastDay()) {
                return sprintf('La date de la course « %s » doit être comprise dans les dates de la compétition.', $name);
            }
        }

        $details = trim((string) ($input['details'] ?? ''));
        if (mb_strlen($details) > RaceProposalCourse::DETAILS_MAX) {
            return sprintf('Précisions trop longues (%d caractères max).', RaceProposalCourse::DETAILS_MAX);
        }

        $course = new RaceProposalCourse($proposal, $user, $name, $date, $details === '' ? null : $details);
        $proposal->addCourse($course);
        $this->em->persist($course);

        return null;
    }

    /** Retire une course : celui qui l'a ajoutée ou l'auteur de la compétition. */
    #[Route('/api/races/{id}/courses/{courseId}', methods: ['DELETE'], requirements: ['id' => '\d+', 'courseId' => '\d+'])]
    public function deleteCourse(int $id, int $courseId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $proposal = $this->findOr404($id);
        $course = null;
        foreach ($proposal->getCourses() as $c) {
            if ($c->getId() === $courseId) {
                $course = $c;
            }
        }
        if ($course === null || !$this->canDeleteCourse($course, $user)) {
            throw $this->createNotFoundException('Course introuvable.');
        }

        $proposal->removeCourse($course);
        $this->em->remove($course);
        $this->em->flush();

        return new JsonResponse($this->serialize($proposal, $user));
    }

    private function canDeleteCourse(RaceProposalCourse $course, User $user): bool
    {
        return $course->getAuthor()?->getId() === $user->getId()
            || $course->getProposal()->getAuthor()->getId() === $user->getId();
    }

    /**
     * Vote d'intérêt. Body : { "status": "interested"|"maybe"|null }
     * (null = retire le vote).
     */
    #[Route('/api/races/{id}/vote', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function vote(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $proposal = $this->findOr404($id);

        $payload = json_decode($request->getContent() ?: '{}', true);
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        if ($status === '') {
            $status = null;
        }
        if ($status !== null && !in_array($status, RaceProposalVote::STATUSES, true)) {
            return new JsonResponse(['error' => 'Statut invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // Date limite de réponse du capitaine dépassée : les réponses sont figées (il peut la repousser).
        if ($proposal->isVotingClosed()) {
            return new JsonResponse(
                ['error' => sprintf('Les réponses sont closes depuis le %s.', $proposal->getRegistrationDeadline()?->modify('+1 day')->format('d/m/Y'))],
                Response::HTTP_CONFLICT,
            );
        }

        $this->setVote($proposal, $user, $status);
        // Plus présent (« Peut-être » ou réponse retirée) : il ne fait plus aucune course de la compétition.
        if ($status !== RaceProposalVote::INTERESTED) {
            foreach ($proposal->getCourses() as $course) {
                $course->removeParticipant($user);
            }
        }
        $this->em->flush();

        return new JsonResponse($this->serialize($proposal, $user));
    }

    /**
     * Je fais (ou ne fais plus) cette course. Body : { "join": true|false }. Faire une course vaut présence à la
     * compétition : la réponse passe à « Intéressé(e) / Je suis inscrit(e) ».
     */
    #[Route('/api/races/{id}/courses/{courseId}/participation', methods: ['POST'], requirements: ['id' => '\d+', 'courseId' => '\d+'])]
    public function courseParticipation(int $id, int $courseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $proposal = $this->findOr404($id);
        $course = null;
        foreach ($proposal->getCourses() as $c) {
            if ($c->getId() === $courseId) {
                $course = $c;
            }
        }
        if ($course === null) {
            throw $this->createNotFoundException('Course introuvable.');
        }
        if ($proposal->isVotingClosed()) {
            return new JsonResponse(
                ['error' => sprintf('Les réponses sont closes depuis le %s.', $proposal->getRegistrationDeadline()?->modify('+1 day')->format('d/m/Y'))],
                Response::HTTP_CONFLICT,
            );
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (is_array($payload) && ($payload['join'] ?? false) === true) {
            $course->addParticipant($user);
            $this->setVote($proposal, $user, RaceProposalVote::INTERESTED);
        } else {
            $course->removeParticipant($user);
        }
        $this->em->flush();

        return new JsonResponse($this->serialize($proposal, $user));
    }

    /** Enregistre la réponse de l'adhérent à la compétition (null = retire sa réponse). Le flush reste à l'appelant. */
    private function setVote(RaceProposal $proposal, User $user, ?string $status): void
    {
        $existing = $this->votes->findOneByProposalAndUser($proposal, $user);
        if ($status === null) {
            if ($existing !== null) {
                $proposal->getVotes()->removeElement($existing);
                $this->em->remove($existing);
            }
        } elseif ($existing !== null) {
            $existing->setStatus($status);
        } else {
            $vote = new RaceProposalVote($proposal, $user, $status);
            $proposal->getVotes()->add($vote);
            $this->em->persist($vote);
        }
    }

    /**
     * Applique les champs du payload. En création, tous les champs
     * obligatoires doivent être présents ; en édition, seuls les champs
     * fournis sont modifiés. Retourne un message d'erreur ou null.
     *
     * @param array<string, mixed> $payload
     */
    private function apply(RaceProposal $proposal, array $payload, bool $creating): ?string
    {
        if ($creating || array_key_exists('name', $payload)) {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                return 'Le nom de la compétition ne peut pas être vide.';
            }
            if (mb_strlen($name) > 150) {
                return 'Nom trop long (150 caractères max).';
            }
            $proposal->setName($name);
        }

        if ($creating || array_key_exists('raceDate', $payload)) {
            $raw = (string) ($payload['raceDate'] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($date === false || $date->format('Y-m-d') !== $raw) {
                return 'Date de début de la compétition invalide.';
            }
            // Une date inchangée n'est pas revalidée : une compétition en cours (commencée hier) reste modifiable.
            $unchanged = $proposal->getId() !== null && $proposal->getRaceDate()->format('Y-m-d') === $raw;
            if (!$unchanged && $date < new \DateTimeImmutable('today')) {
                return 'La date de début de la compétition est déjà passée.';
            }
            $end = $proposal->getRaceEndDate();
            $proposal->setRaceDate($date);
            $proposal->setRaceEndDate($end);
        }

        // Dernier jour (AAAA-MM-JJ, vide = une seule journée).
        if (array_key_exists('raceEndDate', $payload)) {
            $raw = trim((string) ($payload['raceEndDate'] ?? ''));
            if ($raw === '') {
                $proposal->setRaceEndDate(null);
            } else {
                $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if ($end === false || $end->format('Y-m-d') !== $raw) {
                    return 'Date de fin de la compétition invalide.';
                }
                $proposal->setRaceEndDate($end);
            }
        }
        if ($proposal->getLastDay() < $proposal->getRaceDate()) {
            return 'La date de fin doit être le jour du début ou après.';
        }

        if (array_key_exists('url', $payload)) {
            $url = trim((string) ($payload['url'] ?? ''));
            if ($url !== '') {
                // « www.course.fr » → « https://www.course.fr »
                if (!preg_match('#^https?://#i', $url)) {
                    $url = 'https://'.$url;
                }
                if (mb_strlen($url) > 500 || filter_var($url, \FILTER_VALIDATE_URL) === false) {
                    return 'Adresse du site invalide.';
                }
            }
            $proposal->setUrl($url);
        }

        if (array_key_exists('description', $payload)) {
            $description = trim((string) ($payload['description'] ?? ''));
            if (mb_strlen($description) > 3000) {
                return 'Description trop longue (3000 caractères max).';
            }
            $proposal->setDescription($description);
        }

        if (array_key_exists('captain', $payload)) {
            $proposal->setCaptain((bool) $payload['captain']);
        }

        // Date limite de réponse (capitaine uniquement) : AAAA-MM-JJ, vide = aucune. Une date inchangée n'est pas
        // revalidée : on doit pouvoir modifier la proposition après que la date limite est passée.
        if (array_key_exists('registrationDeadline', $payload)) {
            $raw = trim((string) ($payload['registrationDeadline'] ?? ''));
            if ($raw === '') {
                $proposal->setRegistrationDeadline(null);
            } else {
                $deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if ($deadline === false || $deadline->format('Y-m-d') !== $raw) {
                    return 'Date limite de réponse invalide.';
                }
                $unchanged = $proposal->getRegistrationDeadline()?->format('Y-m-d') === $raw;
                if (!$unchanged && $deadline < new \DateTimeImmutable('today')) {
                    return 'La date limite de réponse est déjà passée.';
                }
                $proposal->setRegistrationDeadline($deadline);
            }
        }
        if (!$proposal->isCaptain()) {
            // Pas de capitaine, pas d'inscription groupée : pas de date limite.
            $proposal->setRegistrationDeadline(null);
        } elseif ($proposal->getRegistrationDeadline() !== null && $proposal->getRegistrationDeadline() > $proposal->getRaceDate()) {
            return 'La date limite de réponse doit être au plus tard le premier jour de la compétition.';
        }

        if (array_key_exists('carpoolingEnabled', $payload)) {
            $proposal->setCarpoolingEnabled((bool) $payload['carpoolingEnabled']);
        }

        if ($creating || array_key_exists('type', $payload)) {
            $type = RaceType::tryFrom((string) ($payload['type'] ?? ''));
            if ($type === null) {
                return 'Type de compétition invalide.';
            }
            $proposal->setType($type);
        }

        return null;
    }

    private function findOr404(int $id): RaceProposal
    {
        $proposal = $this->proposals->find($id);
        if ($proposal === null) {
            throw $this->createNotFoundException('Compétition introuvable.');
        }
        return $proposal;
    }

    private function findOwnedOr404(int $id, User $viewer): RaceProposal
    {
        $proposal = $this->findOr404($id);
        if ($proposal->getAuthor()->getId() !== $viewer->getId()) {
            throw $this->createNotFoundException('Compétition introuvable.');
        }
        return $proposal;
    }

    /** @return list<array{value: string, label: string}> */
    private function typeOptions(): array
    {
        return array_map(fn (RaceType $t) => ['value' => $t->value, 'label' => $t->label()], RaceType::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(RaceProposal $r, User $viewer): array
    {
        $author = $r->getAuthor();
        $interested = [];
        $maybe = [];
        $myVote = null;
        foreach ($r->getVotes() as $v) {
            $u = $v->getUser();
            $entry = ['id' => $u->getId(), 'fullName' => $u->getFullName()];
            if ($v->getStatus() === RaceProposalVote::INTERESTED) {
                $interested[] = $entry;
            } else {
                $maybe[] = $entry;
            }
            if ($u->getId() === $viewer->getId()) {
                $myVote = $v->getStatus();
            }
        }

        return [
            'id' => $r->getId(),
            'name' => $r->getName(),
            'raceDate' => $r->getRaceDate()->format('Y-m-d'),
            // Dernier jour d'une compétition sur plusieurs jours ; null = une seule journée.
            'raceEndDate' => $r->getRaceEndDate()?->format('Y-m-d'),
            'url' => $r->getUrl(),
            'description' => $r->getDescription(),
            'captain' => $r->isCaptain(),
            // Capitaine : date limite de réponse (AAAA-MM-JJ, incluse) ; réponses figées une fois dépassée.
            'registrationDeadline' => $r->getRegistrationDeadline()?->format('Y-m-d'),
            'votesClosed' => $r->isVotingClosed(),
            'carpoolingEnabled' => $r->isCarpoolingEnabled(),
            'type' => $r->getType()->value,
            'typeLabel' => $r->getType()->label(),
            'authorId' => $author->getId(),
            'authorFirstName' => $author->getPrenom(),
            'authorFullName' => $author->getFullName(),
            'createdAt' => $r->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $r->getUpdatedAt()?->format(\DATE_ATOM),
            'myVote' => $myVote,
            'interestedCount' => count($interested),
            'maybeCount' => count($maybe),
            'interested' => $interested,
            'maybe' => $maybe,
            // Courses (épreuves) de la compétition, par date puis ordre d'ajout.
            'courses' => array_map(fn (RaceProposalCourse $c) => [
                'id' => $c->getId(),
                'name' => $c->getName(),
                'date' => $c->getCourseDate()?->format('Y-m-d'),
                'details' => $c->getDetails(),
                'authorFirstName' => $c->getAuthor()?->getPrenom(),
                'canDelete' => $this->canDeleteCourse($c, $viewer),
                // Adhérents qui font cette course, et si j'en fais partie.
                'participants' => array_map(
                    static fn (User $u) => ['id' => $u->getId(), 'fullName' => $u->getFullName()],
                    $c->getParticipants()->toArray(),
                ),
                'participating' => $c->hasParticipant($viewer),
            ], $r->getCourses()->toArray()),
        ];
    }
}
