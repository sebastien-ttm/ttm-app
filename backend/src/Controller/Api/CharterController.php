<?php

namespace App\Controller\Api;

use App\Entity\CharterAcceptance;
use App\Entity\User;
use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
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
    ) {
    }

    /**
     * L'user doit-il se voir proposer le formulaire d'acceptation ?
     *
     * Règles (assouplies pour couvrir le cas d'un parent fraîchement
     * inscrit alors que la saison courante n'a pas encore reçu d'import
     * CSV — la précédente version exigeait une UserSeasonMembership
     * exacte sur la saison courante, ce qui échouait pour un parent qui
     * vient juste de rattacher son enfant, tant que l'admin n'avait pas
     * importé la nouvelle saison) :
     *
     *  - Adhérent actif (type=Adherent, isActive=true) → OUI, quel
     *    que soit son historique de UserSeasonMembership.
     *  - Parent externe (type=Externe, subType=parent) rattaché à au
     *    moins un enfant actif adhérent → OUI.
     *  - Compte externe non-parent (ami du club, etc.) → NON.
     *
     * L'unicité par ClubCharter (via CharterAcceptance) garantit qu'une
     * charte n'est signée qu'une fois, donc un adhérent qui a déjà
     * accepté ne verra pas le tunnel deux fois — pas de risque de
     * boucle avec cette version plus permissive.
     */
    private function requiresCharterForCurrentSeason(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        // Adhérent actif → toujours concerné.
        if ($user->isAdherent()) {
            return true;
        }

        // Parent externe rattaché à ≥1 enfant actif adhérent.
        if ($user->isParentExterne()) {
            foreach ($user->getChildren() as $child) {
                if ($child->isActive() && $child->isAdherent()) {
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
