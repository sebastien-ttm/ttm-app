<?php

namespace App\Controller\Admin;

use App\Entity\MenuItem;
use App\Enum\MenuItemType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class MenuItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MenuItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Onglet du menu')
            ->setEntityLabelInPlural('Menu mobile')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label', 'Libellé');
        yield ChoiceField::new('type', 'Type')
            ->setChoices(array_combine(
                array_map(fn ($c) => $c->label(), MenuItemType::cases()),
                MenuItemType::cases()
            ))
            ->renderAsBadges();
        yield TextField::new('target', 'Cible')
            ->setHelp('Pour Page : slug. Pour Lien externe : URL complète. Sinon : ignoré.')
            ->setRequired(false);
        yield TextField::new('icon', 'Icône')
            ->setHelp('Nom Ionicons (ex : "home", "calendar"). Optionnel.')
            ->setRequired(false);
        yield IntegerField::new('position', 'Ordre');
        yield BooleanField::new('isVisible', 'Visible');
    }
}
