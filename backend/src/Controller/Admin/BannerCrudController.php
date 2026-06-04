<?php

namespace App\Controller\Admin;

use App\Entity\Banner;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class BannerCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Banner::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Bannière')
            ->setEntityLabelInPlural('Bannières')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')->setRequired(false);
        yield Field::new('file', 'Image')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'download_uri' => false])
            ->onlyOnForms();
        yield ImageField::new('imagePath', 'Aperçu')
            ->setBasePath('/uploads/banners')
            ->hideOnForm();
        yield UrlField::new('linkUrl', 'Lien (optionnel)')->setRequired(false);
        yield DateTimeField::new('startsAt', 'Active à partir de')->setRequired(false);
        yield DateTimeField::new('endsAt', 'Active jusqu\'à')->setRequired(false);
        yield BooleanField::new('isActive', 'Active');
    }
}
