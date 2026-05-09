<?php

namespace App\Controller\Admin;

use App\Entity\ArticlePhoto;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class ArticlePhotoCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ArticlePhoto::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission('ROLE_ADMIN');
    }

    public function configureFields(string $pageName): iterable
    {
        yield Field::new('file', 'Image')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => true,
                'download_uri' => false,
            ])
            ->onlyOnForms();
        yield ImageField::new('filePath', 'Aperçu')
            ->setBasePath('/uploads/articles')
            ->onlyOnIndex();
        yield TextField::new('alt', 'Description (alt)')->setRequired(false);
        yield IntegerField::new('position', 'Ordre')->setColumns(2);
    }
}
