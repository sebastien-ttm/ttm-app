<?php

namespace App\Controller\Admin;

use App\Entity\PoolBadge;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class PoolBadgeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PoolBadge::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Badge piscines')
            ->setEntityLabelInPlural('Badge piscines')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setHelp(Crud::PAGE_INDEX,
                'Le QR code transmis chaque saison par la mairie pour l\'accès aux piscines. '
                .'Une seule entrée à la fois — remplace celle existante en réuploadant.')
            ->setHelp(Crud::PAGE_EDIT,
                'L\'image sera affichée plein écran sur l\'app mobile. '
                .'Formats acceptés : JPG, PNG, WebP, GIF. Max 5 Mo. '
                .'Si le badge est dans un PDF, extrais le QR en image (capture d\'écran ou export) avant d\'uploader.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Singleton : pas de suppression (on remplace l'image au lieu)
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Badge saison 2025-2026 ». Affiché en haut du QR sur le mobile.');

        yield Field::new('file', 'Image du QR code')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => false,
                'download_uri' => false,
                'image_uri' => true,
            ])
            ->onlyOnForms();

        yield ImageField::new('imagePath', 'Aperçu')
            ->setBasePath('/uploads/pool-badges')
            ->onlyOnIndex();

        yield TextareaField::new('notes', 'Notes')
            ->setRequired(false)
            ->setHelp('Optionnel. Texte court affiché sous le QR (ex : « À présenter à l\'accueil avec une pièce d\'identité »).')
            ->setNumOfRows(3);

        yield DateTimeField::new('updatedAt', 'Mis à jour le')->onlyOnIndex();
    }
}
