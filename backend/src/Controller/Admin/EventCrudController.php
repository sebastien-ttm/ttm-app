<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Enum\ContentAudience;
use App\Enum\EventType;
use App\Enum\Profile;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
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
            ->setDefaultSort(['startsAt' => 'DESC']);
    }

    /**
     * Choix du champ « Type » selon la page.
     * PAGE_NEW → 7 types actuels.
     * PAGE_EDIT + autres → 7 types actuels + les legacy (Entrainement,
     * Social, JourneeCohesion) en fin de liste, préfixés « (ancien) »
     * pour signaler qu'ils sont conservés pour la migration mais plus
     * proposés aux nouveaux événements.
     *
     * @return array<string, EventType>
     */
    private function buildTypeChoices(string $pageName): array
    {
        $adminChoices = EventType::adminChoices();
        if ($pageName === Crud::PAGE_NEW) {
            return array_combine(
                array_map(fn (EventType $c) => $c->label(), $adminChoices),
                $adminChoices,
            );
        }
        $legacy = array_filter(
            EventType::cases(),
            fn (EventType $c) => !in_array($c, $adminChoices, true),
        );
        $choices = [];
        foreach ($adminChoices as $c) {
            $choices[$c->label()] = $c;
        }
        foreach ($legacy as $c) {
            $choices['(ancien) '.$c->label()] = $c;
        }
        return $choices;
    }

    public function configureActions(Actions $actions): Actions
    {
        // Bouton « Voir les votes » disponible sur les événements
        // soumis au vote uniquement (sinon aucun sens).
        $viewVotes = Action::new('viewVotes', 'Voir les votes', 'fa fa-list-check')
            ->linkToRoute('admin_event_attendance_detail', fn (Event $e) => ['id' => $e->getId()])
            ->displayIf(fn (Event $e) => $e->getId() !== null && $e->isVoteEnabled());

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $viewVotes)
            ->add(Crud::PAGE_DETAIL, $viewVotes)
            ->add(Crud::PAGE_EDIT, $viewVotes);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        // Choix proposés dans le dropdown :
        //  - PAGE_NEW : les 7 types actuels uniquement (adminChoices).
        //  - PAGE_EDIT : tous les cases de l'enum, pour qu'un event
        //    dont le type actuel est legacy (Entrainement, Social,
        //    JourneeCohesion…) puisse être migré vers un nouveau type.
        //    Sans cet enrichissement, ChoiceType Symfony reçoit une
        //    valeur initiale qui n'est pas dans ses choix : la soumission
        //    du formulaire échoue silencieusement et l'ancien type reste
        //    en base. On liste d'abord les 7 nouveaux, puis les legacy
        //    en fin de liste, préfixés « (ancien) » pour clarté.
        $typeChoices = $this->buildTypeChoices($pageName);
        yield ChoiceField::new('type', 'Type')
            ->setChoices($typeChoices)
            ->renderAsBadges()
            ->setHelp('La couleur de l\'événement est dérivée automatiquement du type.');
        yield BooleanField::new('isAllDay', 'Toute la journée')
            ->setHelp('Cocher si l\'événement n\'a pas d\'heure précise — l\'heure ne sera pas affichée dans l\'app mobile.');
        yield BooleanField::new('voteEnabled', 'Soumis au vote de présence')
            ->setHelp('Cocher pour proposer aux adhérents les 3 boutons « J\'y serai / Je n\'y serai pas / Je ne sais pas encore » (visible dans la section « Prochainement » et sur la page de détail).');
        yield TextField::new('externalRegistrationUrl', 'URL d\'inscription externe')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Optionnel — uniquement si le vote de présence est activé. '
                .'Ex : lien Njuko / klikego / HelloAsso pour une compétition. '
                .'Renseignée, le bouton « J\'y serai » est remplacé par « Je m\'inscris » '
                .'qui ouvre cette URL dans le navigateur ET compte le vote.');
        yield BooleanField::new('carpoolingEnabled', 'Covoiturage activé')
            ->setHelp('Cocher pour ouvrir une page de covoiturage sur l\'événement : les adhérents peuvent proposer des places (voiture + vélos) ou demander à en réserver. Mise en relation via WhatsApp.');
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
