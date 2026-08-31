<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSeason;
use App\Entity\User;
use App\Enum\Profile;
use App\Enum\UserType;
use App\Repository\TrainingSeasonRepository;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCrudController extends AbstractCrudController
{
    /** Query param name pour le filtre saison (barre de boutons index). */
    private const SEASON_ALL = 'all';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly TrainingSeasonRepository $seasons,
        private readonly RequestStack $requestStack,
        private readonly AdminUrlGenerator $adminUrlGenerator,
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
            ->setSearchFields(['numLicence', 'nom', 'prenom', 'email']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isActive', 'Actif'))
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
                ]))
            ->add('lastLoginAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('numLicence', 'N° licence')
            ->setHelp('Vide pour les comptes externes (parents non adhérents).');
        yield TextField::new('prenom', 'Prénom');
        yield TextField::new('nom');
        yield EmailField::new('email');
        yield TextField::new('telephone', 'Téléphone')->hideOnIndex();

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
                Profile::Performance->value => 'primary',
                Profile::Parent->value => 'warning',
                Profile::Entraineur->value => 'dark',
                Profile::Encadrant->value => 'danger',
            ])
            ->setHelp(
                'Jeune / Sénior sont assignés automatiquement à l\'import CSV selon l\'âge dans l\'année. '
                .'Performance, Parent, Entraîneur, Encadrant sont cochés à la main. '
                .'Le profil Entraîneur ne donne PAS automatiquement l\'accès admin : il faut aussi mettre Rôle = Administrateur.'
            );

        yield ChoiceField::new('role', 'Rôle backend')
            ->setChoices([
                'Utilisateur (mobile uniquement)' => User::ROLE_USER,
                'Éditeur (communication + config)' => User::ROLE_EDITEUR,
                'Entraîneur (éditeur + entraînements + présences)' => User::ROLE_ENTRAINEUR,
                'Administrateur (tout, dont adhérents et charte)' => User::ROLE_ADMIN,
            ])
            ->renderAsBadges([
                User::ROLE_USER => 'secondary',
                User::ROLE_EDITEUR => 'info',
                User::ROLE_ENTRAINEUR => 'primary',
                User::ROLE_ADMIN => 'danger',
            ])
            ->setHelp(
                'Hiérarchie : Administrateur ⊃ Entraîneur ⊃ Éditeur ⊃ Utilisateur. '
                .'« Éditeur » : articles, calendrier, pages, bannière, badge piscines. '
                .'« Entraîneur » : tout l\'éditeur + créneaux, semaine type, plans, présences staff. '
                .'« Administrateur » : tout l\'entraîneur + adhérents, import CSV, charte du club.'
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
        yield BooleanField::new('notifyTrainingPlanEmail', 'Email plans d\'entraînement')
            ->hideOnIndex()
            ->setHelp('Opt-in adhérent : recevoir un email à chaque publication de plan. L\'adhérent gère lui-même cette case depuis son profil mobile.');

        // Profil lié (parent/enfant partageant l'e-mail)
        yield TextField::new('linkLabel', 'Lien')
            ->hideOnForm()
            ->setHelp('Compte principal = se connecte. Les autres profils du même e-mail sont rattachés à lui et accessibles via le switch dans le mobile.');
        yield AssociationField::new('linkedToUser', 'Rattaché à')
            ->setRequired(false)
            ->setHelp('Si ce user partage son e-mail avec son parent, sélectionnez le compte parent. Laisser vide pour un compte principal.')
            ->onlyOnForms();

        // Lien parent ↔ enfant (différent du linkedToUser : ici la
        // relation famille même quand les e-mails diffèrent)
        yield AssociationField::new('children', 'Enfants')
            ->setRequired(false)
            ->setHelp('Pour un compte parent : les enfants adhérents (recherche par nom / licence).')
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
        $current = $this->currentSeasonFilter();
        $todayId = $this->seasons->findCurrent()?->getId();

        $btnAll = Action::new('seasonAll', '👥 Toutes les saisons')
            ->linkToUrl($this->seasonUrl(self::SEASON_ALL))
            ->setCssClass('btn '.($current === self::SEASON_ALL ? 'btn-primary' : 'btn-secondary'))
            ->createAsGlobalAction();
        $result->add(Crud::PAGE_INDEX, $btnAll);

        $seasons = $this->seasons->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'DESC')
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

        return $result;
    }

    /**
     * Filtre l'index par saison d'adhésion selon le query param
     * `?seasonFilter=<id>|all` (défaut : toutes).
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
        return self::SEASON_ALL;
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
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $request = $this->container->get('request_stack')->getCurrentRequest();
            $plain = (string) ($request?->request->all('User')['plainPassword'] ?? '');
            if ($plain !== '') {
                $entityInstance->setPassword($this->hasher->hashPassword($entityInstance, $plain));
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
}
