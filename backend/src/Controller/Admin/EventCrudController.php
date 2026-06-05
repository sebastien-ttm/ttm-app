<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Enum\ContentAudience;
use App\Enum\EventType;
use App\Enum\Profile;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EventCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Event::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Événement')
            ->setEntityLabelInPlural('Calendrier')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['startsAt' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        yield ChoiceField::new('type', 'Type')
            ->setChoices(array_combine(
                array_map(fn ($c) => $c->label(), EventType::cases()),
                EventType::cases()
            ))
            ->renderAsBadges()
            ->setHelp('La couleur de l\'événement est dérivée automatiquement du type.');
        yield BooleanField::new('isAllDay', 'Toute la journée')
            ->setHelp('Cocher si l\'événement n\'a pas d\'heure précise — l\'heure ne sera pas affichée dans l\'app mobile.');
        yield DateTimeField::new('startsAt', 'Début')
            ->setHelp('Si « Toute la journée » est coché, seule la date compte.');
        yield DateTimeField::new('endsAt', 'Fin')
            ->setRequired(false)
            ->setHelp('Optionnel. Pour un événement multi-jours, mettez la date de fin.');
        yield TextField::new('location', 'Lieu')->setRequired(false);
        yield TextareaField::new('description')->setRequired(false)->hideOnIndex();
        yield ChoiceField::new('audience', 'Audience cible')
            ->setChoices(Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Si vide, visible par tous. Sinon, visible uniquement aux profils sélectionnés.');
        yield ChoiceField::new('contentAudience', 'Catégorie de contenu')
            ->setChoices(ContentAudience::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp(
                'Sans tag = événement public (visible par tous). '
                .'Tag « École de Triathlon » : reste visible par tous, mais devient '
                .'l\'unique catégorie visible pour les comptes Dirigeant.'
            );
    }
}
