<?php

namespace App\Controller\Admin;

use App\Entity\VolunteerRole;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Fiches de bénévolat (activités et postes terrain). « Publiée » décide de la visibilité dans l'appli ;
 * le détail rédactionnel (missions, tâches, planning…) s'édite en JSON.
 */
class VolunteerRoleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VolunteerRole::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Fiche')
            ->setEntityLabelInPlural('Fiches (bénévolat)')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['name', 'title', 'manualNames'])
            ->setPaginatorPageSize(60);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('pole', 'Pôle');
        yield ChoiceField::new('kind', 'Type')
            ->setChoices(['Activité' => VolunteerRole::KIND_ACTIVITY, 'Responsable terrain' => VolunteerRole::KIND_FIELD]);
        yield TextField::new('name', 'Nom');
        yield TextField::new('title', 'Intitulé du rôle')->setRequired(false)->hideOnIndex();
        yield BooleanField::new('published', 'Publiée')->renderAsSwitch(true);
        yield IntegerField::new('requiredCount', 'Nb requis')
            ->setRequired(false)
            ->setHelp('Responsables terrain surtout : nombre de personnes recherchées.');
        yield TextField::new('manualNames', 'Déjà en place (saisie libre)')
            ->setRequired(false)
            ->setHelp('Prénoms séparés par des virgules, affichés tels quels. Préférez l\'affectation d\'un compte dans l\'appli.');
        yield AssociationField::new('activity', 'Activité de rattachement')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Responsables terrain uniquement.');
        yield IntegerField::new('pendingCount', 'En attente')->hideOnForm()->setSortable(false);
        yield TextareaField::new('pitch', 'Accroche')->setRequired(false)->setNumOfRows(2)->hideOnIndex();
        yield TextareaField::new('contentJson', 'Contenu (JSON)')
            ->onlyOnForms()
            ->setNumOfRows(24)
            ->setHelp('Missions, tâches, planning… Un JSON invalide est ignoré (le contenu précédent est conservé).');
        yield IntegerField::new('position', 'Ordre')->onlyOnForms();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('pole', 'Pôle'))
            ->add(ChoiceFilter::new('kind', 'Type')->setChoices(['Activité' => VolunteerRole::KIND_ACTIVITY, 'Responsable terrain' => VolunteerRole::KIND_FIELD]))
            ->add(BooleanFilter::new('published', 'Publiée'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW);
    }
}
