<?php

namespace App\Controller\Admin;

use App\Entity\StaticPage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class StaticPageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return StaticPage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Page statique')
            ->setEntityLabelInPlural('Pages statiques')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['title' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        yield TextField::new('slug')
            ->setHelp('Identifiant unique en URL : minuscules, chiffres, tirets. Ex: lieux-rdv');
        yield TextEditorField::new('content', 'Contenu')->onlyOnForms();
        yield BooleanField::new('isPublished', 'Publié');
        yield DateTimeField::new('updatedAt', 'Mis à jour le')->onlyOnIndex();
    }
}
