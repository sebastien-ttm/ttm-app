<?php

namespace App\Controller\Admin;

use App\Entity\PhotoUpload;
use App\Service\Piwigo\PiwigoClient;
use App\Service\Piwigo\PiwigoException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Modération des photos envoyées depuis l'appli dans la galerie Piwigo :
 * liste (auteur, album, date, lien vers la photo sur le site) et
 * suppression — la photo est alors aussi supprimée de Piwigo.
 */
class PhotoUploadCrudController extends AbstractCrudController
{
    public function __construct(private readonly PiwigoClient $piwigo)
    {
    }

    public static function getEntityFqcn(): string
    {
        return PhotoUpload::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Photo')
            ->setEntityLabelInPlural('Photos du club')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setSearchFields(['albumName', 'user.nom', 'user.prenom'])
            ->setHelp('index', 'Photos publiées depuis l\'appli dans la galerie Piwigo. Supprimer une ligne supprime aussi la photo de Piwigo.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Auteur');
        yield TextField::new('albumName', 'Album');
        yield IntegerField::new('piwigoImageId', 'N° Piwigo');
        yield UrlField::new('piwigoUrl', 'Voir sur Piwigo');
        yield DateTimeField::new('createdAt', 'Envoyée le')
            ->setFormat('d MMM yyyy HH:mm');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT);
    }

    public function deleteEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof PhotoUpload) {
            try {
                $this->piwigo->deleteImage($entityInstance->getPiwigoImageId());
            } catch (PiwigoException $e) {
                // Photo déjà supprimée côté Piwigo ou galerie injoignable :
                // on retire quand même la ligne, mais on prévient.
                $this->addFlash('warning', 'Suppression dans Piwigo impossible ('.$e->getMessage().'). Vérifiez la photo dans l\'admin Piwigo.');
            }
        }
        parent::deleteEntity($em, $entityInstance);
    }
}
