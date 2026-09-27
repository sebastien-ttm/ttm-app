<?php

namespace App\Controller\Admin;

use App\Entity\BibOffer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Vue admin en lecture seule de la bourse aux dossards — modération
 * uniquement : pas de création ni d'édition depuis le backend, seule la
 * suppression est possible (comme la bourse aux équipements).
 */
class BibOfferCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BibOffer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Offre de dossards')
            ->setEntityLabelInPlural('Bourse aux dossards')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['raceDate' => 'DESC'])
            ->setSearchFields(['raceName', 'description', 'author.nom', 'author.prenom']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('author', 'Auteur');
        yield TextField::new('raceName', 'Course');
        yield DateField::new('raceDate', 'Date')
            ->setFormat('d MMM yyyy');
        yield IntegerField::new('quantity', 'Dossards');
        yield TextField::new('priceLabel', 'Échange / prix')
            ->setSortable(false);
        yield TextareaField::new('description', 'Précisions')
            ->hideOnIndex();
        yield BooleanField::new('paused', 'En pause')
            ->renderAsSwitch(false);
        yield DateTimeField::new('createdAt', 'Publiée le')
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
