<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSlotTemplate;
use App\Enum\Sport;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

class TrainingSlotTemplateCrudController extends AbstractCrudController
{
    private const DAY_CHOICES = [
        'Lundi' => 1,
        'Mardi' => 2,
        'Mercredi' => 3,
        'Jeudi' => 4,
        'Vendredi' => 5,
        'Samedi' => 6,
        'Dimanche' => 7,
    ];

    /**
     * EasyAdmin / Symfony Form attendent des valeurs typées identiques à la
     * propriété : ici Sport (enum), pas la valeur string.
     *
     * @return array<string, Sport>
     */
    private static function sportChoices(): array
    {
        $out = [];
        foreach (Sport::cases() as $c) {
            $out[$c->label()] = $c;
        }
        return $out;
    }

    public static function getEntityFqcn(): string
    {
        return TrainingSlotTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Créneau (semaine type)')
            ->setEntityLabelInPlural('Semaine type d\'entraînement')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['dayOfWeek' => 'ASC', 'startTime' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('dayOfWeek', 'Jour')
            ->setChoices(self::DAY_CHOICES)
            ->renderAsBadges();

        yield TimeField::new('startTime', 'Heure de début')
            ->setFormat('HH:mm')
            ->setFormTypeOption('input', 'datetime_immutable')
            ->setFormTypeOption('widget', 'single_text');

        yield IntegerField::new('durationMinutes', 'Durée (min)');

        yield ChoiceField::new('sport', 'Sport')
            ->setChoices(self::sportChoices())
            ->renderAsBadges();

        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Natation technique » ou « Vélo home-trainer »');

        yield TextField::new('location', 'Lieu')
            ->setHelp('Ex : « Piscine Léo-Lagrange » ou « Stade Daniel Faucher »');

        yield TextareaField::new('description', 'Description')
            ->hideOnIndex()
            ->setHelp('Optionnel. Précisions, niveau, matériel requis, etc.')
            ->setNumOfRows(3);

        yield BooleanField::new('isActive', 'Actif')
            ->setHelp('Décocher pour retirer ce créneau de la semaine type (sans le supprimer).');

        yield IntegerField::new('position', 'Position')
            ->hideOnIndex()
            ->setHelp('Optionnel : ordre d\'affichage à heure égale (0 par défaut).');

        yield DateField::new('startsAt', 'Date de début (optionnel)')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Si défini, ce créneau ne s\'applique qu\'à partir de cette date (ex. PPG démarrant en janvier). Laisser vide = toute la saison.');

        yield DateField::new('endsAt', 'Date de fin (optionnel)')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Si défini, ce créneau ne s\'applique que jusqu\'à cette date (inclus).');

        yield ChoiceField::new('audience', 'Audience cible')
            ->setChoices(\App\Enum\Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Si vide, visible par tous. Sinon, visible uniquement aux profils sélectionnés.');
    }
}
