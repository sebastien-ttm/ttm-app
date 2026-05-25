<?php

namespace App\Controller\Admin;

use App\Entity\MembershipSettings;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;

class MembershipSettingsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MembershipSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réglages d\'adhésion')
            ->setEntityLabelInPlural('Réglages d\'adhésion')
            ->setEntityPermission('ROLE_ADMIN')
            ->setHelp(Crud::PAGE_INDEX,
                'Réglages liés à la gestion des comptes adhérents. '
                .'Une seule configuration active à la fois.')
            ->setHelp(Crud::PAGE_EDIT,
                'La période de grâce permet aux anciens adhérents non encore '
                .'renouvelés de garder l\'accès à l\'app le temps qu\'ils '
                .'régularisent leur licence.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('oldMembersValidUntil', 'Anciens adhérents valides jusqu\'au')
            ->setRequired(false)
            ->setHelp(
                'Tant qu\'on est avant cette date, les adhérents <strong>absents du dernier '
                .'CSV importé</strong> restent actifs (le temps qu\'ils renouvellent leur '
                .'licence). Après cette date, le prochain import CSV les désactive '
                .'normalement. Laisser vide pour désactiver immédiatement à chaque import.'
            );
    }
}
