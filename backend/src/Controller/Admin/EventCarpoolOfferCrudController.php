<?php

namespace App\Controller\Admin;

use App\Entity\EventCarpoolOffer;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Vue admin des propositions de covoiturage — événements DU CALENDRIER
 * et propositions de course confondus. Contrairement aux autres CRUD
 * de contenu adhérent (bourse, courses proposées…), celui-ci est
 * pleinement éditable par un admin : création, modification et
 * changement du sujet (événement ↔ course) depuis le backend.
 */
class EventCarpoolOfferCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EventCarpoolOffer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Covoiturage')
            ->setEntityLabelInPlural('Covoiturages')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['user.nom', 'user.prenom', 'event.title', 'raceProposal.name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('subjectLabel', 'Sujet')
            ->onlyOnIndex()
            ->setSortable(false);

        yield AssociationField::new('user', 'Adhérent')
            ->autocomplete()
            ->setRequired(true);

        yield AssociationField::new('event', 'Événement')
            ->autocomplete()
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Choisir SOIT un événement SOIT une proposition de course, pas les deux.');

        yield AssociationField::new('raceProposal', 'Proposition de course')
            ->autocomplete()
            ->setRequired(false)
            ->hideOnIndex();

        yield ChoiceField::new('role', 'Rôle')
            ->setChoices([
                'Conducteur' => EventCarpoolOffer::ROLE_DRIVER,
                'Passager' => EventCarpoolOffer::ROLE_PASSENGER,
            ])
            ->renderAsBadges();

        yield IntegerField::new('seatsAvailable', 'Places dispo')
            ->setRequired(false)
            ->setHelp('Conducteur uniquement.');

        yield IntegerField::new('bikeSlots', 'Places vélo')
            ->setRequired(false)
            ->setHelp('Conducteur uniquement.');

        yield BooleanField::new('isFull', 'Voiture pleine')
            ->renderAsSwitch(false)
            ->hideOnIndex();

        yield DateTimeField::new('createdAt', 'Créé le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Modifié le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnForm()
            ->hideOnIndex();
    }

    /**
     * EventCarpoolOffer n'a pas de constructeur sans argument (user
     * requis) — on pré-remplit avec l'admin courant, qu'il changera
     * via le champ Adhérent. Le sujet (event/raceProposal) reste vide :
     * l'admin choisit l'un des deux, validé par
     * EventCarpoolOffer::validateSubject() avant sauvegarde.
     */
    public function createEntity(string $entityFqcn): EventCarpoolOffer
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // Ne peut normalement pas arriver sur une route admin
            // authentifiée — garde-fou pour le type de retour non-null
            // attendu par le constructeur.
            throw $this->createAccessDeniedException('Utilisateur admin introuvable.');
        }

        return new EventCarpoolOffer($user);
    }
}
