<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\EventTag;
use App\Enum\ContentAudience;
use App\Enum\Profile;
use App\Repository\EventTagRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EventCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EventTagRepository $tagsRepo,
    ) {
    }

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

        // Tags configurables (remplacent l'ancien enum EventType).
        // Multi-sélection : un événement peut porter plusieurs tags —
        // la couleur d'affichage est celle du 1er tag (position asc).
        yield AssociationField::new('tags', 'Tags')
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('choice_label', 'name')
            ->setFormTypeOption('query_builder', function () {
                return $this->tagsRepo->createQueryBuilder('t')
                    ->andWhere('t.active = true')
                    ->orderBy('t.position', 'ASC')
                    ->addOrderBy('t.name', 'ASC');
            })
            ->setRequired(false)
            ->setHelp('Sélectionne un ou plusieurs tags. La couleur d\'affichage est celle du premier tag. Les tags se gèrent dans « Tags d\'événements ».')
            ->formatValue(fn ($value) => $this->formatTagList($value));

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
        // Rich text (TinyMCE) : permet d'insérer des liens hypertexte
        // (bouton `link` de la toolbar) et des boutons stylés (menu
        // « Bouton » — variantes primary/secondary/outline). Rendu côté
        // mobile via RichContent avec styles cohérents.
        yield TextEditorField::new('description', 'Descriptif')
            ->setRequired(false)
            ->hideOnIndex()
            ->setNumOfRows(10)
            ->setHelp('Utilisez le bouton link (🔗) pour insérer un lien hypertexte, ou le menu « Bouton » pour un lien mis en avant.');
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

    /**
     * Sur les pages INDEX/DETAIL, l'AssociationField multi-values
     * affiche par défaut « X éléments » — pas très parlant. On rend
     * ici la liste des libellés séparés par des virgules.
     */
    private function formatTagList(mixed $value): string
    {
        if ($value === null) return '';
        $items = is_iterable($value) ? $value : [$value];
        $names = [];
        foreach ($items as $t) {
            if ($t instanceof EventTag) {
                $names[] = $t->getName();
            }
        }
        return implode(', ', $names);
    }
}
