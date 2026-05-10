<?php

namespace App\Controller\Admin;

use App\Entity\ClubCharter;
use App\Repository\ClubCharterRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ClubCharterCrudController extends AbstractCrudController
{
    public function __construct(private readonly ClubCharterRepository $charters)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ClubCharter::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Charte')
            ->setEntityLabelInPlural('Chartes du club')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Charte de l\'adhérent — Saison 2026 »');
        yield TextField::new('version', 'Version / Saison')
            ->setHelp('Identifiant lisible, ex : « 2026 » ou « 2026-rev2 »');
        yield TextEditorField::new('content', 'Contenu')
            ->setHelp('Texte intégral présenté à l\'adhérent. L\'éditeur supporte les images, listes, mise en forme.')
            ->onlyOnForms();
        yield BooleanField::new('isActive', 'Active')
            ->setHelp('Activer cette charte la rend obligatoire pour tous les utilisateurs et désactive automatiquement les autres.');
        yield DateTimeField::new('publishedAt', 'Publiée le')->onlyOnIndex();
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->ensureSingleActive($em, $entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->ensureSingleActive($em, $entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    private function ensureSingleActive(EntityManagerInterface $em, $entity): void
    {
        if (!$entity instanceof ClubCharter || !$entity->isActive()) {
            return;
        }
        // Désactive toutes les autres chartes (sauf celle-ci si elle a un id)
        $this->charters->deactivateAllExcept($entity->getId());
    }
}
