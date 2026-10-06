<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\Profile;
use App\Enum\UserType;
use App\Message\SendMagicLinkEmailMessage;
use App\Repository\UserRepository;
use App\Service\MagicLinkService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Comptes temporaires pour les adhérents dont la licence n'est pas
 * encore validée par la ligue (absents du CSV FFTri) : création avec
 * nom, prénom, date de naissance et email, puis suivi. L'import CSV
 * complète automatiquement le compte (n° de licence, adresse…) dès que
 * l'adhérent apparaît dans le fichier (CsvImportService).
 */
#[IsGranted('ROLE_ADMIN')]
class PendingLicenceController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'pending_licence';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly MagicLinkService $magicLinks,
        private readonly MessageBusInterface $bus,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/licences-en-attente', name: 'admin_pending_licence', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_pending_licence')) {
            return $r;
        }

        $rows = array_map(fn (User $u) => [
            'user' => $u,
            'editUrl' => $this->editUrl($u),
        ], $this->users->findPendingLicence());

        return $this->render('admin/pending_licence.html.twig', [
            'rows' => $rows,
            'form' => $request->getSession()->get('pending_licence_form', []),
        ]);
    }

    #[Route('/admin/licences-en-attente', name: 'admin_pending_licence_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $prenom = trim((string) $request->request->get('prenom', ''));
        $nom = trim((string) $request->request->get('nom', ''));
        $email = mb_strtolower(trim((string) $request->request->get('email', '')), 'UTF-8');
        $dateRaw = (string) $request->request->get('dateNaissance', '');
        $sendWelcome = $request->request->get('sendWelcome') === '1';
        $session = $request->getSession();
        // Valeurs saisies conservées en cas d'erreur (formulaire re-rempli).
        $session->set('pending_licence_form', compact('prenom', 'nom', 'email', 'dateRaw', 'sendWelcome'));

        $dateNaissance = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw) ?: null;
        if ($prenom === '' || $nom === '' || $email === '' || $dateNaissance === null) {
            $this->addFlash('danger', 'Prénom, nom, date de naissance et email sont obligatoires.');
            return $this->redirectToRoute('admin_pending_licence');
        }
        if ($dateNaissance > new \DateTimeImmutable('today') || $dateNaissance < new \DateTimeImmutable('-100 years')) {
            $this->addFlash('danger', 'Date de naissance invalide.');
            return $this->redirectToRoute('admin_pending_licence');
        }

        // Doublon : même personne déjà en base (compte normal ou temporaire).
        $existing = $this->users->createQueryBuilder('u')
            ->where('u.dateNaissance = :d')->setParameter('d', $dateNaissance->format('Y-m-d'))
            ->getQuery()->getResult();
        foreach ($existing as $u) {
            if (UserRepository::normalizeName($u->getPrenom()) === UserRepository::normalizeName($prenom)
                && UserRepository::normalizeName($u->getNom()) === UserRepository::normalizeName($nom)) {
                $this->addFlash('warning', sprintf(
                    '%s (né(e) le %s) a déjà un compte%s : <a href="%s">voir la fiche</a>. Réactivez-le plutôt que d\'en créer un second.',
                    htmlspecialchars($u->getFullName()),
                    $dateNaissance->format('d/m/Y'),
                    $u->isActive() ? '' : ' (désactivé)',
                    htmlspecialchars($this->editUrl($u)),
                ));
                return $this->redirectToRoute('admin_pending_licence');
            }
        }

        $user = (new User())
            ->setPrenom($prenom)
            ->setNom($nom)
            ->setEmail($email)
            ->setDateNaissance($dateNaissance)
            ->setType(UserType::Adherent)
            ->setSubType(User::SUBTYPE_CLUB)
            ->setRole(User::ROLE_USER)
            ->setStatutLicence('En attente')
            ->setIsActive(true)
            ->setProfiles([Profile::principalFromBirthDate($dateNaissance)->value])
            ->setPendingLicenceSince(new \DateTimeImmutable());

        // Email partagé (famille) : rattache au compte principal existant,
        // comme le fait l'import CSV (linkSharedEmailProfiles).
        $sameEmail = $this->users->findAllActiveByEmail($email);
        if ($sameEmail !== []) {
            $primary = null;
            foreach ($sameEmail as $u) {
                if ($u->getLinkedToUser() === null) {
                    $primary = $u;
                    break;
                }
            }
            $user->setLinkedToUser($primary ?? $sameEmail[0]->getLinkedToUser() ?? $sameEmail[0]);
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $msgs = [];
            foreach ($errors as $err) {
                $msgs[] = $err->getPropertyPath().' : '.$err->getMessage();
            }
            $this->addFlash('danger', htmlspecialchars(implode(' ; ', $msgs)));
            return $this->redirectToRoute('admin_pending_licence');
        }

        $this->em->persist($user);
        $this->em->flush();
        $session->remove('pending_licence_form');

        if ($sendWelcome) {
            $issued = $this->magicLinks->issue($user);
            $this->bus->dispatch(new SendMagicLinkEmailMessage(
                userId: $user->getId(),
                clearToken: $issued['token'],
                isWelcome: true,
                isRenewal: false,
            ));
        }

        $this->addFlash('success', sprintf(
            'Compte temporaire créé pour %s%s. Il sera complété automatiquement dès que sa licence apparaîtra dans un import CSV.',
            // Les flashs EasyAdmin sont rendus en HTML brut.
            htmlspecialchars($user->getFullName()),
            $sendWelcome ? ', email de bienvenue envoyé' : '',
        ));
        return $this->redirectToRoute('admin_pending_licence');
    }

    private function editUrl(User $u): string
    {
        return $this->adminUrlGenerator->unsetAll()
            ->setController(UserCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($u->getId())
            ->generateUrl();
    }
}
