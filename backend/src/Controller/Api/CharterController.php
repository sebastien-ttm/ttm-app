<?php

namespace App\Controller\Api;

use App\Entity\CharterAcceptance;
use App\Entity\User;
use App\Enum\AdherentKind;
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
     * L'user est-il adhérent pour la saison courante ? Base pour décider
     * si le formulaire d'acceptation doit lui être présenté.
     *
     * Si aucune saison courante n'est définie (config incomplète) : on
     * retombe sur `true` — mieux vaut afficher le formulaire à tout le
     * monde que de bloquer la feature entière.
     */
    private function isAdherentForCurrentSeason(User $user): bool
    {
        $currentSeason = $this->seasons->findCurrent();
        if ($currentSeason === null) {
            return true;
        }
        return $this->memberships->findOneByUserAndSeason($user, $currentSeason) !== null;
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
        // Détermine le kind selon l'historique d'adhésions :
        // >1 adhésion = renouvellement, sinon nouveau. Le repo fait un
        // fallback sur `all` s'il n'y a pas de formulaire dédié.
        $kind = $this->memberships->countForUser($user) > 1
            ? AdherentKind::Renewal
            : AdherentKind::New;
        $charter = $this->charters->findCurrent($user, $kind);

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

        // Filtre saison : n'imposer le formulaire qu'aux adhérents de la
        // saison courante (via UserSeasonMembership). Un user détaché
        // manuellement d'une saison (correction d'import, etc.) n'est
        // plus bloqué par l'écran d'acceptation.
        $isCurrentAdherent = $this->isAdherentForCurrentSeason($user);

        return new JsonResponse([
            'charter' => $this->serializer->charter($charter),
            'acceptanceRequired' => $isPreview || (!$hasAccepted && $isCurrentAdherent),
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
        $kind = $this->memberships->countForUser($user) > 1
            ? AdherentKind::Renewal
            : AdherentKind::New;
        $charter = $this->charters->findCurrent($user, $kind);

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
        if ($charter->hasForm()) {
            $payload = json_decode($request->getContent() ?: '{}', true);
            $rawAnswers = is_array($payload) ? ($payload['answers'] ?? null) : null;

            // Restreint le schéma aux champs applicables au profil de l'user.
            // Un champ hors audience n'est ni requis, ni validé, ni stocké —
            // même si le client tente de l'envoyer.
            $applicableFields = $this->formValidator->filterForUser(
                $charter->getFields(),
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
