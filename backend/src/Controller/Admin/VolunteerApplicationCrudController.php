<?php

namespace App\Controller\Admin;

use App\Entity\VolunteerApplication;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Candidatures des adhérents aux fiches de bénévolat : vue d'ensemble, correction du statut et export CSV.
 * La validation courante se fait dans l'appli par les responsables de pôle (avec notification à l'adhérent) ;
 * un changement de statut fait ici ne notifie personne.
 */
class VolunteerApplicationCrudController extends AbstractCrudController
{
    private const STATUS_CHOICES = [
        'En attente' => VolunteerApplication::STATUS_PENDING,
        'Validée' => VolunteerApplication::STATUS_ACCEPTED,
        'Déclinée' => VolunteerApplication::STATUS_DECLINED,
    ];

    public static function getEntityFqcn(): string
    {
        return VolunteerApplication::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Candidature')
            ->setEntityLabelInPlural('Candidatures (bénévolat)')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['user.nom', 'user.prenom', 'role.name'])
            ->setPaginatorPageSize(50);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Adhérent')->hideWhenUpdating();
        yield TextField::new('poleName', 'Pôle')->hideOnForm()->setSortable(false);
        yield AssociationField::new('role', 'Fiche')->hideWhenUpdating();
        yield ChoiceField::new('status', 'Statut')
            ->setChoices(self::STATUS_CHOICES)
            ->renderAsBadges([
                VolunteerApplication::STATUS_PENDING => 'warning',
                VolunteerApplication::STATUS_ACCEPTED => 'success',
                VolunteerApplication::STATUS_DECLINED => 'secondary',
            ])
            ->setHelp('Ici, un changement de statut ne notifie pas l\'adhérent.');
        yield TextareaField::new('message', 'Message')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield DateTimeField::new('createdAt', 'Le')->setFormat('d MMM yyyy HH:mm')->hideOnForm();
        yield AssociationField::new('decidedBy', 'Décidée par')->hideOnForm()->hideOnIndex();
        yield AssociationField::new('assignedBy', 'Affectée par')->hideOnForm()->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(self::STATUS_CHOICES))
            ->add(EntityFilter::new('role', 'Fiche'))
            ->add(EntityFilter::new('user', 'Adhérent'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $export = Action::new('exportCsv', 'Exporter en CSV', 'fa fa-file-csv')
            ->linkToRoute('admin_volunteer_export')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $export)
            ->disable(Action::NEW);
    }
}
