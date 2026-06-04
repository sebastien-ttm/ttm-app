<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Enum\EventType;
use App\Enum\Profile;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
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
            ->renderAsBadges();
        yield DateTimeField::new('startsAt', 'Début');
        yield DateTimeField::new('endsAt', 'Fin')->setRequired(false);
        yield TextField::new('location', 'Lieu')->setRequired(false);
        yield TextareaField::new('description')->setRequired(false)->hideOnIndex();
        yield ColorField::new('color', 'Couleur')
            ->setRequired(false)
            ->setHelp('Vide = couleur par défaut du type.');
        yield ChoiceField::new('audience', 'Audience cible')
            ->setChoices(Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Si vide, visible par tous. Sinon, visible uniquement aux profils sélectionnés.');
    }
}
