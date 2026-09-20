<?php

namespace App\Controller\Admin;

use App\Entity\EventTag;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD des tags d'événement — remplace l'ancien enum EventType figé.
 * Chaque tag porte son propre libellé, sa couleur et un ordre
 * d'affichage. Deux modes de retrait :
 *  - désactiver (active=false) : le tag reste visible sur les
 *    événements qui le portent, mais n'est plus proposé en saisie.
 *  - supprimer : les liaisons M2M sont détruites (ON DELETE CASCADE).
 */
class EventTagCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EventTag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tag d\'événement')
            ->setEntityLabelInPlural('Tags d\'événements')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['position' => 'ASC', 'name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Libellé');
        yield ColorField::new('color', 'Couleur')
            ->setHelp('Format #RRGGBB. Utilisée comme fond des pastilles calendrier et des chips côté mobile.');
        yield IntegerField::new('position', 'Ordre')
            ->setHelp('Plus petit = plus haut / plus tôt dans la liste. Défaut 100.');
        yield BooleanField::new('active', 'Actif')
            ->setHelp('Décoché : le tag reste sur les événements qui le portent mais n\'est plus proposé à la saisie.');
    }
}
