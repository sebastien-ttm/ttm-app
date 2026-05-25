<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSeason;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;

class TrainingSeasonCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TrainingSeason::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Saison d\'entraînement')
            ->setEntityLabelInPlural('Saison d\'entraînement')
            ->setEntityPermission('ROLE_COACH')
            ->setHelp(Crud::PAGE_INDEX,
                'Définit la période sur laquelle la <strong>semaine type</strong> est appliquée. '
                .'En dehors de cette période (par ex. l\'été), aucun créneau récurrent n\'est affiché — '
                .'mais on peut toujours ajouter des créneaux occasionnels pour les vacances.')
            ->setHelp(Crud::PAGE_EDIT,
                'Laisser les deux dates vides = la semaine type s\'applique toute l\'année.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Singleton : une seule saison à la fois.
        // On retire delete pour ne pas perdre la config par accident,
        // et new si une saison existe déjà.
        return $actions
            ->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('startsAt', 'Début de saison')
            ->setRequired(false)
            ->setHelp('Date de début (incluse). Ex. 25 août pour une saison qui démarre fin août.');
        yield DateField::new('endsAt', 'Fin de saison')
            ->setRequired(false)
            ->setHelp('Date de fin (incluse). Ex. 5 juillet pour une saison qui se termine début juillet.');
    }
}
