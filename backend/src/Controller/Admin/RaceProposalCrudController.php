<?php

namespace App\Controller\Admin;

use App\Entity\RaceProposal;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Vue admin en lecture seule des courses proposées par les adhérents
 * (onglet Social côté mobile) — modération uniquement : pas de création
 * ni d'édition depuis le backend, seule la suppression est possible.
 */
class RaceProposalCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RaceProposal::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Course proposée')
            ->setEntityLabelInPlural('Courses proposées')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['raceDate' => 'DESC'])
            ->setSearchFields(['name', 'author.nom', 'author.prenom']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Course');
        yield DateField::new('raceDate', 'Date')
            ->setFormat('d MMM yyyy');
        yield TextField::new('typeLabel', 'Type')
            ->setSortable(false);
        yield AssociationField::new('author', 'Proposée par');
        yield BooleanField::new('captain', 'Capitaine')
            ->renderAsSwitch(false);
        yield BooleanField::new('carpoolingEnabled', 'Covoiturage activé')
            ->renderAsSwitch(false)
            ->hideOnIndex();
        yield IntegerField::new('interestedCount', 'Intéressés')
            ->setSortable(false);
        yield UrlField::new('url', 'Site')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Proposée le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT);
    }
}
