<?php

namespace App\Controller\Api;

use App\Entity\RaceProposal;
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
 * Courses proposées (onglet Social) : n'importe quel adhérent propose
 * une course (capitaine ou non), les autres indiquent leur intérêt.
 * Seules les courses à venir sont listées ; l'auteur peut modifier ou
 * supprimer sa proposition.
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
     * Création. JSON : name, raceDate (AAAA-MM-JJ), url?, description?, captain, registrationDeadline? (capitaine :
     * date limite de réponse AAAA-MM-JJ), type.
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
        $this->em->flush();

        return new JsonResponse($this->serialize($proposal, $user));
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
                return 'Le nom de la course ne peut pas être vide.';
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
                return 'Date de la course invalide.';
            }
            if ($date < new \DateTimeImmutable('today')) {
                return 'La date de la course est déjà passée.';
            }
            $proposal->setRaceDate($date);
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
            return 'La date limite de réponse doit être au plus tard le jour de la course.';
        }

        if (array_key_exists('carpoolingEnabled', $payload)) {
            $proposal->setCarpoolingEnabled((bool) $payload['carpoolingEnabled']);
        }

        if ($creating || array_key_exists('type', $payload)) {
            $type = RaceType::tryFrom((string) ($payload['type'] ?? ''));
            if ($type === null) {
                return 'Type de course invalide.';
            }
            $proposal->setType($type);
        }

        return null;
    }

    private function findOr404(int $id): RaceProposal
    {
        $proposal = $this->proposals->find($id);
        if ($proposal === null) {
            throw $this->createNotFoundException('Course introuvable.');
        }
        return $proposal;
    }

    private function findOwnedOr404(int $id, User $viewer): RaceProposal
    {
        $proposal = $this->findOr404($id);
        if ($proposal->getAuthor()->getId() !== $viewer->getId()) {
            throw $this->createNotFoundException('Course introuvable.');
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
        ];
    }
}
