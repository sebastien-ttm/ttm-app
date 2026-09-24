<?php

namespace App\Controller\Admin;

use App\Entity\MarketplaceListing;
use App\Service\Marketplace\MarketplaceListingPhotoService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Vue admin en lecture seule des annonces de la bourse aux équipements
 * (onglet Club côté mobile) — modération uniquement : pas de création
 * ni d'édition depuis le backend, seule la suppression est possible.
 */
class MarketplaceListingCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MarketplaceListingPhotoService $photoService,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return MarketplaceListing::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Annonce')
            ->setEntityLabelInPlural('Bourse aux équipements')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['title', 'description', 'author.nom', 'author.prenom']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('author', 'Auteur');
        yield TextField::new('title', 'Titre');
        yield TextareaField::new('description', 'Description')
            ->hideOnIndex();
        yield IntegerField::new('photoCount', 'Photos')
            ->setSortable(false);
        yield BooleanField::new('paused', 'En pause')
            ->renderAsSwitch(false);
        yield DateTimeField::new('createdAt', 'Publiée le')
            ->setFormat('d MMM yyyy HH:mm');
        yield DateTimeField::new('updatedAt', 'Modifiée le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT);
    }

    /**
     * Nettoie les photos sur disque avant suppression — sans ça, seules
     * les lignes en base disparaissent (cascade Doctrine) et les
     * fichiers restent orphelins sous public/uploads/marketplace/.
     */
    public function deleteEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof MarketplaceListing) {
            $this->photoService->removeAllFiles($entityInstance);
        }
        parent::deleteEntity($em, $entityInstance);
    }
}
