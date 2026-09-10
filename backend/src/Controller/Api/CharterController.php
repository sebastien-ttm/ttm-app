<?php

namespace App\Controller\Api;

use App\Entity\CharterAcceptance;
use App\Entity\User;
use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Charter\FormSchemaValidator;
use App\Service\Serializer\ApiSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CharterController extends AbstractController
{
    public function __construct(
        private readonly ClubCharterRepository $charters,
        private readonly CharterAcceptanceRepository $acceptances,
        private readonly EntityManagerInterface $em,
        private readonly ApiSerializer $serializer,
        private readonly FormSchemaValidator $formValidator,
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
    ) {
    }

    /**
     * L'user doit-il se voir proposer le formulaire d'acceptation pour
     * la saison courante ? Vrai si :
     *  - il est lui-même adhérent avec UserSeasonMembership pour la
     *    saison courante, OU
     *  - c'est un parent externe rattaché à au moins un enfant actif
     *    adhérent AVEC une UserSeasonMembership pour la saison courante.
     *
     * Pourquoi cette contrainte stricte sur la saison courante ?
     * Un adhérent qui n'a pas encore renouvelé sa licence pour la
     * saison N+1 reste `isActive=true` pendant la période de grâce,
     * mais ne doit PAS se voir proposer la charte N+1 tant que son
     * renouvellement n'est pas enregistré (import CSV). Idem pour un
     * parent externe : tant qu'aucun de ses enfants n'a de membership
     * pour la saison courante, il ne signe pas.
     *
     * Cas particulier : si aucune saison n'est configurée (setup
     * incomplet), on retombe sur `false` pour éviter de spammer.
     */
    private function requiresCharterForCurrentSeason(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }
        $currentSeason = $this->seasons->findCurrent();
        if ($currentSeason === null) {
            return false;
        }

        // Adhérent : uniquement s'il a une membership pour la saison en cours.
        if ($user->isAdherent()) {
            return $this->memberships->findOneByUserAndSeason($user, $currentSeason) !== null;
        }

        // Parent externe : au moins un enfant actif adhérent avec
        // membership pour la saison en cours (pas seulement isActive,
        // qui peut être hérité de la saison précédente pendant la
        // période de grâce).
        if ($user->isParentExterne()) {
            foreach ($user->getChildren() as $child) {
                if (!$child->isActive() || !$child->isAdherent()) continue;
                if ($this->memberships->findOneByUserAndSeason($child, $currentSeason) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns the currently-active charter and whether the current user
     * still needs to accept it.
     */
    #[Route('/api/charter/current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $charter = $this->charters->findCurrent($user);

        if ($charter === null) {
            return new JsonResponse([
                'charter' => null,
                'acceptanceRequired' => false,
            ]);
        }

        // Mode aperçu : l'admin doit pouvoir itérer sur le contenu et le
        // formulaire → on présente TOUJOURS le formulaire, même après
        // acceptation, et indépendamment de sa saison d'adhésion.
        // Sortir du mode preview (retirer previewUser dans le CRUD) rétablit
        // le comportement standard.
        $isPreview = $charter->getPreviewUser()?->getId() === $user->getId();
        $hasAccepted = $this->acceptances->hasAccepted($user, $charter);

        // Filtre saison : n'imposer le formulaire qu'aux personnes
        // concernées par la saison courante — adhérents directs ET
        // parents externes d'un enfant adhérent.
        $isCurrentAdherent = $this->requiresCharterForCurrentSeason($user);

        // Engagements du tunnel — désormais versionnés par saison sur
        // le ClubCharter courant, filtrés par audience selon les profils
        // (Parent/Jeune vs Sénior).
        $applicableFields = $this->formValidator->filterForUser(
            $charter->getFields() ?? [],
            $user->getProfiles(),
        );

        // hasEverAccepted : vrai si l'user a déjà signé UN formulaire
        // d'acceptation (toutes chartes/saisons confondues). Utilisé par
        // le mobile pour masquer la carte « à relire à tout moment » aux
        // adhérents importés par erreur qui n'ont jamais rien signé.
        $hasEverAccepted = $this->acceptances->countForUser($user) > 0;

        return new JsonResponse([
            'charter' => $this->serializer->charter($charter, $applicableFields),
            'acceptanceRequired' => $isPreview || (!$hasAccepted && $isCurrentAdherent),
            'hasEverAccepted' => $hasEverAccepted,
        ]);
    }

    /**
     * Records the user's acceptance of the currently-active charter.
     */
    #[Route('/api/me/charter/accept', methods: ['POST'])]
    public function accept(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $charter = $this->charters->findCurrent($user);

        if ($charter === null) {
            return new JsonResponse(
                ['error' => 'Aucune charte active.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($this->acceptances->hasAccepted($user, $charter)) {
            // Idempotent : déjà acceptée
            return new JsonResponse(['ok' => true, 'alreadyAccepted' => true]);
        }

        $answers = null;
        $currentFields = $charter->getFields() ?? [];
        if (count($currentFields) > 0) {
            $payload = json_decode($request->getContent() ?: '{}', true);
            $rawAnswers = is_array($payload) ? ($payload['answers'] ?? null) : null;

            // Restreint le schéma aux champs applicables au profil de l'user.
            // Un champ hors audience n'est ni requis, ni validé, ni stocké —
            // même si le client tente de l'envoyer.
            $applicableFields = $this->formValidator->filterForUser(
                $currentFields,
                $user->getProfiles(),
            );

            $errors = $this->formValidator->validateAnswers($applicableFields, $rawAnswers);
            if ($errors !== []) {
                return new JsonResponse(
                    ['error' => 'Formulaire invalide.', 'details' => $errors],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            // Ne conserver que les clés du schéma applicable (pas d'inputs parasites)
            $allowedIds = array_map(
                static fn (array $f) => $f['id'] ?? null,
                $applicableFields,
            );
            $answers = array_intersect_key(
                is_array($rawAnswers) ? $rawAnswers : [],
                array_flip(array_filter($allowedIds, 'is_string')),
            );
        }

        $acceptance = new CharterAcceptance($user, $charter, $request->getClientIp(), $answers);
        $this->em->persist($acceptance);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'acceptedAt' => $acceptance->getAcceptedAt()->format(\DATE_ATOM),
        ]);
    }
}
