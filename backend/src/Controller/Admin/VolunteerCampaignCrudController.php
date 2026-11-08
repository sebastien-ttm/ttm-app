<?php

namespace App\Controller\Admin;

use App\Entity\VolunteerCampaign;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Campagnes de bénévolat d'événement (ex. Breathman 2027). Créées en général par la commande
 * app:volunteer:import ; ici on règle la publication, l'ouverture des candidatures et les textes.
 */
class VolunteerCampaignCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return VolunteerCampaign::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Événement (bénévolat)')
            ->setEntityLabelInPlural('Événements (bénévolat)')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['startsOn' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Identifiant')
            ->hideOnIndex()
            ->setHelp('Identifiant stable utilisé par la commande d\'import (ex. breathman-2027).');
        yield DateField::new('startsOn', 'Premier jour')->setFormat('d MMM yyyy');
        yield DateField::new('endsOn', 'Dernier jour')->setFormat('d MMM yyyy')->setRequired(false)->hideOnIndex();
        yield TextField::new('location', 'Lieu')->setRequired(false)->hideOnIndex();
        yield DateField::new('applicationsDeadline', 'Répondre avant le')
            ->setFormat('d MMM yyyy')
            ->setRequired(false)
            ->setHelp('Affiché aux adhérents ; ne bloque pas les candidatures.');
        yield TextareaField::new('intro', 'Accroche')->setRequired(false)->setNumOfRows(3)->hideOnIndex();
        yield BooleanField::new('published', 'Publié dans l\'appli')
            ->renderAsSwitch(false)
            ->setHelp('Désignez d\'abord les responsables de chaque pôle (menu Pôles), puis publiez.');
        yield BooleanField::new('applicationsOpen', 'Candidatures ouvertes')
            ->renderAsSwitch(false)
            ->setHelp('Décochez pour clore les candidatures en laissant les fiches visibles.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }
}
