<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserCategory;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Adhérent')
            ->setEntityLabelInPlural('Adhérents')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['nom' => 'ASC'])
            ->setSearchFields(['numLicence', 'nom', 'prenom', 'email']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isActive', 'Actif'))
            ->add(ChoiceFilter::new('categorie', 'Catégorie')
                ->setChoices(['Sénior' => UserCategory::Senior, 'Jeune' => UserCategory::Jeune]));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('numLicence', 'N° licence');
        yield TextField::new('prenom', 'Prénom');
        yield TextField::new('nom');
        yield EmailField::new('email');
        yield TextField::new('telephone', 'Téléphone')->hideOnIndex();
        yield ChoiceField::new('categorie', 'Catégorie')
            ->setChoices(['Sénior' => UserCategory::Senior, 'Jeune' => UserCategory::Jeune])
            ->renderAsBadges();
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
            ]);
        yield DateField::new('dateNaissance', 'Date de naissance')->hideOnIndex();
        yield ChoiceField::new('sexe', 'Sexe')
            ->setChoices(['Homme' => 'm', 'Femme' => 'f'])
            ->setRequired(false)
            ->hideOnIndex();
        yield TextareaField::new('adresse', 'Adresse')
            ->hideOnIndex()
            ->setNumOfRows(4);
        yield TextField::new('statutLicence', 'Statut licence')->hideOnIndex();
        yield ChoiceField::new('roles')
            ->setChoices(['Adhérent' => 'ROLE_USER', 'Entraîneur' => 'ROLE_COACH', 'Administrateur' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderAsBadges();
        yield BooleanField::new('isActive', 'Actif');
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
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $form = $this->getContext()?->getEntity()->getInstance();
            // Plain password handling via raw request
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
