<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSeason;
use App\Entity\User;
use App\Enum\BoardRole;
use App\Enum\Profile;
use App\Enum\UserType;
use App\Filter\ProfileTypeFilter;
use App\Message\SendGestionnaireWelcomeEmailMessage;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Board\MinutesSignatureService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCrudController extends AbstractCrudController
{
    /** Query param name pour le filtre saison (barre de boutons index). */
    private const SEASON_ALL = 'all';

    /** @var array<int, array{seasons: int, firstYear: ?int, lastYear: ?int}>|null chargée à la première ligne affichée */
    private ?array $seniority = null;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly RequestStack $requestStack,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly MessageBusInterface $bus,
        private readonly MinutesSignatureService $signatures,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['nom' => 'ASC'])
            ->setPaginatorPageSize(50)
            ->setSearchFields(['numLicence', 'nom', 'prenom', 'email', 'clubEmail']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isActive', 'Actif'))
            ->add(BooleanFilter::new('externalAssociation', 'Association sportive externe'))
            // Sous-filtre « Profil / type de compte » : Adhérent, Externe, Jeune, Sénior, Parent, Entraîneur, Encadrant,
            // Gestionnaire externe — plusieurs choix possibles (l'un OU l'autre).
            ->add(ProfileTypeFilter::new('profileType', 'Profil / type de compte'))
            ->add(ChoiceFilter::new('type', 'Type')
                ->setChoices(['Adhérent' => UserType::Adherent, 'Externe' => UserType::Externe]))
            ->add(ChoiceFilter::new('subType', 'Sous-type')
                ->setChoices([
                    'Licencié club' => User::SUBTYPE_CLUB,
                    'Licencié autre club' => User::SUBTYPE_AUTRE_CLUB,
                    'Parent' => User::SUBTYPE_PARENT,
                    'Ami' => User::SUBTYPE_AMI,
                ]))
            ->add(ChoiceFilter::new('role', 'Rôle')
                ->setChoices([
                    'Utilisateur (mobile)' => User::ROLE_USER,
                    'Éditeur (communication)' => User::ROLE_EDITEUR,
                    'Entraîneur (entraînements)' => User::ROLE_ENTRAINEUR,
                    'Administrateur (tout)' => User::ROLE_ADMIN,
                    'Responsable RH (présences staff)' => User::ROLE_RH,
                ]))
            ->add('lastLoginAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('numLicence', 'N° licence')
            ->setHelp('Vide pour les comptes externes (parents non adhérents).');
        yield TextField::new('prenom', 'Prénom');
        yield TextField::new('nom');
        yield EmailField::new('email', 'E-mail personnel')
            ->setHelp('Adresse renseignée sur FFTRi (import du CSV) : identifiant de connexion et destinataire des e-mails de l\'appli.');
        yield EmailField::new('clubEmail', 'E-mail du club')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Adresse du club (ex. prenom.nom@triathlontoulousemetropole.com), si elle diffère de l\'e-mail personnel. Servira aux invitations Google Agenda individuelles des réunions du CoDir (pour le moment, l\'invitation part à l\'adresse générique du CoDir) ; à défaut, l\'e-mail personnel sera utilisé. L\'import FFTRi ne la modifie pas.');
        yield TextField::new('telephone', 'Téléphone')->hideOnIndex();
        yield TextField::new('telephoneUrgence', 'Téléphone d\'urgence')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Contact d\'urgence : lu à l\'import du CSV FFTri, visible des seuls encadrants du groupe d\'entraînement de l\'adhérent.');

        yield ChoiceField::new('type', 'Type de compte')
            ->setChoices(UserType::enumChoices())
            ->renderAsBadges([
                UserType::Adherent->value => 'success',
                UserType::Externe->value => 'warning',
            ]);

        yield ChoiceField::new('subType', 'Sous-type')
            ->setChoices([
                'Licencié au club (par défaut)' => User::SUBTYPE_CLUB,
                'Licencié dans un autre club (manuel)' => User::SUBTYPE_AUTRE_CLUB,
                'Parent' => User::SUBTYPE_PARENT,
                'Ami du club' => User::SUBTYPE_AMI,
            ])
            ->renderAsBadges([
                User::SUBTYPE_CLUB => 'success',
                User::SUBTYPE_AUTRE_CLUB => 'info',
                User::SUBTYPE_PARENT => 'warning',
                User::SUBTYPE_AMI => 'secondary',
            ])
            ->setHelp(
                'Adhérent → club (défaut, importé du CSV) OU autre_club (créé manuellement). '
                .'Externe → parent (inscription mobile) OU ami (ancien adhérent, créé à la main).'
            );

        yield ChoiceField::new('profiles', 'Profils')
            ->setChoices(Profile::choices())
            ->allowMultipleChoices()
            ->renderAsBadges([
                Profile::Jeune->value => 'success',
                Profile::Senior->value => 'info',
                Profile::Parent->value => 'warning',
                Profile::Entraineur->value => 'dark',
                Profile::Encadrant->value => 'danger',
                Profile::Gestionnaire->value => 'primary',
            ])
            ->setHelp(
                'Jeune / Sénior sont assignés automatiquement à l\'import CSV selon l\'âge dans l\'année. '
                .'Parent, Entraîneur, Encadrant sont cochés à la main. '
                .'Le profil Entraîneur ne donne PAS automatiquement l\'accès admin : il faut aussi mettre Rôle = Administrateur. '
                .'À l\'inverse, le profil Gestionnaire externe donne automatiquement le rôle Administrateur (accès complet au backend) : '
                .'à réserver aux gestionnaires de confiance extérieurs au club. En le retirant, le rôle repasse à Utilisateur.'
            );

        // EnumType : convertit '' ↔ null proprement, et une valeur non
        // vide en instance BoardRole. ChoiceField::setChoices avec des
        // strings passait des '' au setter typé ?BoardRole → TypeError.
        yield ChoiceField::new('boardRole', 'Rôle CODIR')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class' => BoardRole::class,
                'required' => false,
                'placeholder' => '— Aucun —',
                'choice_label' => fn (BoardRole $r) => $r->label(),
            ])
            ->renderAsBadges([
                BoardRole::President->value => 'danger',
                BoardRole::Tresorier->value => 'warning',
                BoardRole::Secretaire->value => 'info',
                BoardRole::MembreCoDir->value => 'secondary',
            ])
            ->setHelp('Renseigné uniquement pour les élus du CODIR. Les 3 rôles Président / Trésorier / Secrétaire composent le Bureau, affiché en tête de la page trombinoscope Comité.');

        // Rôle complémentaire, cumulable avec le rôle CODIR ci-dessus (qui doit être renseigné : Symfony l'applique avant cette case).
        yield BooleanField::new('minibusManager', 'Gestionnaire du minibus')
            ->renderAsSwitch(false)
            ->hideOnIndex()
            ->setHelp('Valide ou refuse les demandes d\'emprunt du minibus du club dans l\'appli (Staff › Comité directeur › Emprunt minibus) et peut modifier la charte d\'utilisation. Cumulable avec un rôle CODIR, qui est nécessaire : sans rôle CODIR, la case est ignorée.');

        // Signature manuscrite : celle du Secrétaire est insérée automatiquement au bas du compte rendu des réunions du
        // CoDir. Envoi hors formulaire mappé (comme le mot de passe) : voir handleSignature().
        yield Field::new('signatureUpload', 'Signature (compte rendu du CODIR)')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'required' => false,
                'mapped' => false,
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp'],
            ])
            ->onlyOnForms()
            ->setHelp('Image de la signature manuscrite (PNG, JPG ou WebP, 2 Mo au plus ; fond transparent de préférence). '
                .'Elle est insérée automatiquement au bas du compte rendu des réunions du CODIR quand la personne a le rôle Secrétaire. '
                .'Un nouvel envoi remplace la signature précédente.');
        yield Field::new('removeSignature', 'Supprimer la signature enregistrée')
            ->setFormType(CheckboxType::class)
            ->setFormTypeOptions(['required' => false, 'mapped' => false])
            ->onlyOnForms();
        yield TextField::new('signatureStatus', 'Signature (compte rendu du CODIR)')
            ->onlyOnDetail();

        yield TextField::new('clubFunction', 'Fonction au club')
            ->setRequired(false)
            ->setHelp('Ex : « Référent triathlon jeunes », « Coach natation », « Gestion des inscriptions ». Attendue non vide pour les Entraîneurs et les membres du CODIR. Vide pour les autres adhérents.');

        yield ChoiceField::new('role', 'Rôle backend')
            ->setChoices([
                'Utilisateur (mobile uniquement)' => User::ROLE_USER,
                'Éditeur (communication + config)' => User::ROLE_EDITEUR,
                'Entraîneur (éditeur + entraînements + présences)' => User::ROLE_ENTRAINEUR,
                'Administrateur (tout, dont utilisateurs et charte)' => User::ROLE_ADMIN,
                'Responsable RH (« Présence entraînements » + récap des heures)' => User::ROLE_RH,
            ])
            ->renderAsBadges([
                User::ROLE_USER => 'secondary',
                User::ROLE_EDITEUR => 'info',
                User::ROLE_ENTRAINEUR => 'primary',
                User::ROLE_ADMIN => 'danger',
                User::ROLE_RH => 'warning',
            ])
            ->setHelp(
                'Hiérarchie : Administrateur ⊃ Entraîneur ⊃ Éditeur ⊃ Utilisateur. '
                .'« Éditeur » : articles, calendrier, pages, bannière, badge piscines. '
                .'« Entraîneur » : tout l\'éditeur + créneaux, semaine type, plans, présences staff. '
                .'« Administrateur » : tout l\'entraîneur + utilisateurs, import CSV, charte du club. '
                .'« Responsable RH » : hors hiérarchie, accès au backend limité à « Présence entraînements » '
                .'(lecture seule) et au récap des heures encadrées par entraîneur ; reçoit ce récap par e-mail '
                .'chaque samedi soir. '
                .'Un compte avec le profil « Gestionnaire externe » est toujours Administrateur : pour changer son rôle, '
                .'retirer d\'abord ce profil.'
            );

        yield BooleanField::new('canManageTrainerSchedule', 'Entraîneur référent')
            ->hideOnIndex()
            ->setHelp(
                'Peut modifier les présences du staff dans « Présence entraînements » et éditer '
                .'les « Semaines types entraîneurs ». Indépendant du rôle : sans cette case, un '
                .'compte Entraîneur voit « Présence entraînements » en lecture seule. '
                .'Les administrateurs peuvent toujours tout modifier.'
            );

        yield TextField::new('categorieAge', 'Catégorie FFTri')->hideOnIndex();
        yield ChoiceField::new('typeLicence', 'Type de licence')
            ->setChoices([
                'Compétition' => 'Compétition',
                'Loisir' => 'Loisir',
                'Dirigeant' => 'Dirigeant',
            ])
            ->setRequired(false)
            ->renderAsBadges([
                'Compétition' => 'success',
                'Loisir' => 'info',
                'Dirigeant' => 'warning',
            ])
            ->hideOnIndex();
        yield DateField::new('dateNaissance', 'Date de naissance')->hideOnIndex();
        yield ChoiceField::new('sexe', 'Sexe')
            ->setChoices(['Homme' => 'm', 'Femme' => 'f'])
            ->setRequired(false)
            ->hideOnIndex();
        yield TextareaField::new('adresse', 'Adresse')
            ->hideOnIndex()
            ->setNumOfRows(4);
        yield TextField::new('statutLicence', 'Statut licence')->hideOnIndex();
        yield BooleanField::new('isActive', 'Actif');
        yield BooleanField::new('externalAssociation', 'Association sportive externe')
            ->setHelp('Jeune d\'une association sportive externe : aucun accès à l\'application. Cocher désactive le compte ; décocher ne le réactive pas (cocher « Actif » ensuite, en enregistrant une première fois).')
            ->hideOnIndex();
        // Ancienneté : nombre de saisons d'adhésion (imports courants et historiques), avec la première saison.
        yield TextField::new('seniority', 'Ancienneté')
            ->hideOnForm()
            ->setSortable(false)
            ->formatValue(fn ($value, ?User $user) => $user === null ? '' : $this->seniorityLabel($user));
        yield BooleanField::new('notifyTrainingPlanEmail', 'Email plans d\'entraînement')
            ->hideOnIndex()
            ->setHelp('Seule notification e-mail réglable : un email à chaque publication de plan. L\'adhérent gère lui-même cette case depuis son profil mobile.');

        // Profil lié (parent/enfant partageant l'e-mail)
        yield TextField::new('linkLabel', 'Lien')
            ->hideOnForm()
            ->setHelp('Compte principal = se connecte. Les autres profils du même e-mail sont rattachés à lui et accessibles via le switch dans le mobile.');
        yield AssociationField::new('linkedToUser', 'Rattaché à')
            ->setRequired(false)
            ->setHelp('Si ce user partage son e-mail avec son parent, sélectionnez le compte parent (il reçoit automatiquement le profil Parent). Laisser vide pour un compte principal.')
            ->onlyOnForms();

        // Lien parent ↔ enfant (différent du linkedToUser : ici la
        // relation famille même quand les e-mails diffèrent)
        yield AssociationField::new('children', 'Enfants')
            ->setRequired(false)
            ->setHelp('Pour un compte parent : les enfants adhérents (recherche par nom / licence). Le profil Parent est ajouté automatiquement à l\'enregistrement.')
            ->onlyOnForms()
            ->autocomplete();

        yield DateTimeField::new('lastLoginAt', 'Dernière connexion')
            ->setFormat('d MMM YYYY HH:mm')
            ->hideOnForm()
            ->setHelp('Mise à jour automatiquement à chaque connexion (mobile ou admin).');
        yield IntegerField::new('loginCount', 'Connexions')
            ->onlyOnDetail();
        yield DateTimeField::new('lastCsvSyncAt', 'Dernier import CSV')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnDetail();

        // password field — only displayed on edit, optional reset
        if (in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true)) {
            yield TextField::new('plainPassword', 'Nouveau mot de passe')
                ->setFormType(\Symfony\Component\Form\Extension\Core\Type\PasswordType::class)
                ->setHelp('Laissez vide pour ne pas changer.')
                ->setRequired(false)
                ->onlyOnForms()
                ->setFormTypeOption('mapped', false);
        }
    }

    public function configureActions(Actions $actions): Actions
    {
        // Bouton « Se connecter en tant que » : disponible aux admins,
        // sur les comptes actifs uniquement. Ouvre un nouvel onglet pour
        // ne pas casser la session admin en cours.
        $impersonate = Action::new('impersonate', '🎭 Se connecter en tant que', null)
            ->linkToRoute('admin_impersonate', fn (User $u) => ['id' => $u->getId()])
            ->displayIf(fn (User $u) => $u->getId() !== null && $u->isActive())
            ->setHtmlAttributes(['target' => '_blank']);

        $memberships = Action::new('memberships', '🧾 Adhésions / facture', null)
            ->linkToRoute('admin_user_memberships', fn (User $u) => ['id' => $u->getId()])
            ->displayIf(fn (User $u) => $u->getId() !== null);

        $result = $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $impersonate)
            ->add(Crud::PAGE_INDEX, $memberships)
            ->add(Crud::PAGE_DETAIL, $impersonate)
            ->add(Crud::PAGE_DETAIL, $memberships)
            ->add(Crud::PAGE_EDIT, $impersonate)
            ->add(Crud::PAGE_EDIT, $memberships);

        // Barre de boutons « saison » au-dessus de l'index — même pattern
        // que TrainingSlotTemplate. Un bouton par saison connue + « Toutes ».
        // EasyAdmin affiche les actions globales dans l'ordre INVERSE de leur
        // ajout (global_actions|reverse) : on ajoute donc les saisons de la
        // plus ancienne à la plus récente, puis « Toutes », pour obtenir à
        // l'écran « Toutes », puis la saison la plus récente… la plus ancienne.
        $current = $this->currentSeasonFilter();
        $todayId = $this->seasons->findCurrent()?->getId();

        $seasons = $this->seasons->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()->getResult();
        foreach ($seasons as $s) {
            /** @var TrainingSeason $s */
            $id = (string) $s->getId();
            $isSelected = $current === $id;
            $isCurrent = $todayId !== null && $todayId === $s->getId();
            $label = (string) $s.($isCurrent ? ' ⭐' : '');

            $css = 'btn ';
            if ($isSelected) {
                $css .= 'btn-primary';
            } elseif ($isCurrent) {
                $css .= 'btn-info';
            } else {
                $css .= 'btn-secondary';
            }
            $result->add(Crud::PAGE_INDEX, Action::new('userSeason_'.$id, $label)
                ->linkToUrl($this->seasonUrl($id))
                ->setCssClass($css)
                ->createAsGlobalAction());
        }

        $btnAll = Action::new('seasonAll', '👥 Toutes les saisons')
            ->linkToUrl($this->seasonUrl(self::SEASON_ALL))
            ->setCssClass('btn '.($current === self::SEASON_ALL ? 'btn-primary' : 'btn-secondary'))
            ->createAsGlobalAction();
        $result->add(Crud::PAGE_INDEX, $btnAll);

        return $result;
    }

    /**
     * Filtre l'index par saison d'adhésion selon le query param
     * `?seasonFilter=<id>|all` (défaut : la saison courante, à défaut toutes).
     */
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $filter = $this->currentSeasonFilter();
        if ($filter === self::SEASON_ALL || !ctype_digit($filter)) {
            return $qb;
        }
        // EXISTS sub-query : garde les users ayant un UserSeasonMembership
        // pour la saison sélectionnée.
        $sub = $this->em->createQueryBuilder()
            ->select('1')
            ->from('App\Entity\UserSeasonMembership', 'usm')
            ->where('usm.user = entity')
            ->andWhere('usm.season = :seasonId');
        $qb->andWhere($qb->expr()->exists($sub->getDQL()))
            ->setParameter('seasonId', (int) $filter);
        return $qb;
    }

    /** « 12 saisons · depuis 2012-2013 », « 1 saison · 2025-2026 », « — » sans aucune adhésion enregistrée. */
    private function seniorityLabel(User $user): string
    {
        $this->seniority ??= $this->memberships->seniorityByUser();
        $s = $this->seniority[(int) $user->getId()] ?? null;
        if ($s === null) {
            return '—';
        }
        $label = $s['seasons'].' saison'.($s['seasons'] > 1 ? 's' : '');
        if ($s['firstYear'] !== null) {
            $first = $s['firstYear'].'-'.($s['firstYear'] + 1);
            $label .= $s['seasons'] > 1 ? ' · depuis '.$first : ' · '.$first;
        }

        return $label;
    }

    private function currentSeasonFilter(): string
    {
        $req = $this->requestStack->getCurrentRequest();
        $raw = $req?->query->get('seasonFilter');
        if ($raw === self::SEASON_ALL) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return $raw;
        }
        // Sans choix explicite : la liste montre les adhérents de la saison courante (bouton ⭐ sélectionné).
        // Seulement sur la liste — l'autocomplétion (champ « Enfants »…) passe aussi par createIndexQueryBuilder
        // et doit continuer de chercher parmi tous les adhérents.
        if ($this->getContext()?->getCrud()?->getCurrentAction() !== Action::INDEX) {
            return self::SEASON_ALL;
        }
        // Dès qu'on recherche ou qu'on filtre (inactifs, externes, gestionnaires… qui n'ont pas d'adhésion sur la saison
        // courante), on cherche parmi tous les comptes : le bouton « Toutes les saisons » s'allume. Un bouton de saison
        // reste applicable à tout moment (il ajoute alors seasonFilter à l'URL, filtres conservés).
        if ($this->hasSearchOrFilters($req)) {
            return self::SEASON_ALL;
        }
        $currentId = $this->seasons->findCurrent()?->getId();

        return $currentId !== null ? (string) $currentId : self::SEASON_ALL;
    }

    /** La requête porte-t-elle une recherche (`query`) ou au moins un filtre EasyAdmin (`filters[...]`) ? */
    private function hasSearchOrFilters(?Request $req): bool
    {
        if ($req === null) {
            return false;
        }
        $params = $req->query->all();
        $search = $params['query'] ?? null;
        $filters = $params['filters'] ?? null;

        return (is_string($search) && trim($search) !== '') || (is_array($filters) && $filters !== []);
    }

    private function seasonUrl(string $filter): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('seasonFilter', $filter)
            ->generateUrl();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $request = $this->container->get('request_stack')->getCurrentRequest();
            $plain = (string) ($request?->request->all('User')['plainPassword'] ?? '');
            if ($plain !== '') {
                $entityInstance->setPassword($this->hasher->hashPassword($entityInstance, $plain));
            }
            $this->reconcileLinkedToUser($entityInstance);
            $this->ensureParentProfileWhenChildren($entityInstance);
            $this->handleSignature($entityInstance, $request);
        }
        parent::persistEntity($entityManager, $entityInstance);
        // Après l'enregistrement : le compte a son identifiant, et le message d'envoi le retrouve en base.
        if ($entityInstance instanceof User && $entityInstance->isGestionnaire()) {
            $this->sendGestionnaireWelcome($entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $becameGestionnaire = false;
        if ($entityInstance instanceof User) {
            $request = $this->container->get('request_stack')->getCurrentRequest();
            $plain = (string) ($request?->request->all('User')['plainPassword'] ?? '');
            if ($plain !== '') {
                $entityInstance->setPassword($this->hasher->hashPassword($entityInstance, $plain));
            }
            $this->reconcileLinkedToUser($entityInstance);
            $this->ensureParentProfileWhenChildren($entityInstance);
            $hadGestionnaire = $this->hadGestionnaireProfile($entityManager, $entityInstance);
            $this->revokeAdminOfRemovedGestionnaire($hadGestionnaire, $entityInstance);
            $becameGestionnaire = !$hadGestionnaire && $entityInstance->isGestionnaire();
            $this->handleSignature($entityInstance, $request);
        }
        parent::updateEntity($entityManager, $entityInstance);
        if ($becameGestionnaire) {
            $this->sendGestionnaireWelcome($entityInstance);
        }
    }

    /**
     * Signature manuscrite du compte (insérée au compte rendu du CoDir si la personne est Secrétaire) : un fichier
     * envoyé remplace la précédente ; la case « Supprimer » l'efface. Le formulaire s'appelle « User » (comme pour
     * plainPassword) et ces deux champs ne sont pas liés à l'entité.
     */
    private function handleSignature(User $user, ?Request $request): void
    {
        if ($request === null) {
            return;
        }
        $file = $request->files->all('User')['signatureUpload'] ?? null;
        if ($file instanceof UploadedFile) {
            $error = $this->signatures->store($user, $file);
            if ($error !== null) {
                $this->addFlash('danger', $error);
            }

            return;
        }
        if (!empty($request->request->all('User')['removeSignature'])) {
            $this->signatures->delete($user);
        }
    }

    /** Le compte avait-il déjà le profil Gestionnaire externe avant cette modification (valeur en base) ? */
    private function hadGestionnaireProfile(EntityManagerInterface $entityManager, User $user): bool
    {
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($user);

        return in_array(Profile::Gestionnaire->value, (array) ($original['profiles'] ?? []), true);
    }

    /**
     * Retirer le profil Gestionnaire externe retire aussi le rôle Administrateur qu'il donnait : sans cela, le formulaire
     * (qui renvoie le rôle affiché, « Administrateur ») laisserait un ancien gestionnaire administrateur sans que
     * personne l'ait décidé. Pour le garder administrateur, remettre le rôle dans une seconde modification.
     */
    private function revokeAdminOfRemovedGestionnaire(bool $hadProfile, User $user): void
    {
        if ($hadProfile && !$user->isGestionnaire() && $user->isAdmin()) {
            $user->setRole(User::ROLE_USER);
            $this->addFlash('warning', sprintf(
                'Le profil Gestionnaire externe de %s a été retiré : son rôle backend est repassé à « Utilisateur ». '
                .'Modifiez de nouveau le compte pour lui rendre un autre rôle.',
                htmlspecialchars($user->getFullName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ));
        }
    }

    /**
     * E-mail d'accès d'un gestionnaire externe (lien pour créer son mot de passe, boutons application et backend),
     * envoyé en arrière-plan. Le compte doit être actif et avoir une adresse : sinon on prévient l'administrateur
     * plutôt que de laisser croire que le message est parti.
     */
    private function sendGestionnaireWelcome(User $user): void
    {
        $name = htmlspecialchars($user->getFullName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!$user->isActive() || !$user->getEmail()) {
            $this->addFlash('warning', sprintf(
                'L\'e-mail d\'accès de %s n\'a pas été envoyé : le compte est désactivé ou n\'a pas d\'adresse e-mail.',
                $name,
            ));

            return;
        }

        $this->bus->dispatch(new SendGestionnaireWelcomeEmailMessage((int) $user->getId()));
        $this->addFlash('success', sprintf(
            'Un e-mail d\'accès (création du mot de passe, application et backend) va être envoyé à %s.',
            htmlspecialchars((string) $user->getEmail(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ));
    }

    /**
     * Un compte à qui l'on rattache des enfants (champ « Enfants ») devient Parent : le profil ouvre la gestion des
     * enfants dans l'appli, et le formulaire ne le coche pas tout seul.
     */
    private function ensureParentProfileWhenChildren(User $user): void
    {
        if (!$user->getChildren()->isEmpty()) {
            $user->ensureParentProfile();
        }
    }

    /**
     * Un compte "Rattaché à" (linkedToUser) n'est censé exister QUE tant
     * qu'il partage réellement l'e-mail de son compte principal — c'est
     * cette adresse commune qui justifie de le masquer de la recherche
     * par e-mail (findOneByEmail() filtre linkedToUser IS NULL).
     *
     * Si l'e-mail a été changé (ici ou via le changement d'e-mail en
     * libre-service, qui ne s'applique qu'aux comptes principaux) au
     * point de ne plus correspondre à celui du principal, le lien
     * devient un piège silencieux : le compte a sa propre adresse mais
     * reste introuvable au login, avec ou sans mot de passe configuré.
     * On le détache automatiquement pour qu'il redevienne un compte
     * principal connectable.
     */
    private function reconcileLinkedToUser(User $user): void
    {
        $primary = $user->getLinkedToUser();
        if ($primary === null) {
            return;
        }
        if (mb_strtolower($user->getEmail(), 'UTF-8') !== mb_strtolower($primary->getEmail(), 'UTF-8')) {
            $user->setLinkedToUser(null);
            $this->addFlash('info', sprintf(
                '« %s » a désormais une adresse e-mail différente de « %s » — détaché automatiquement '
                .'pour redevenir un compte principal (sinon il resterait introuvable au login).',
                $user->getFullName(),
                $primary->getFullName(),
            ));
        }
    }
}
