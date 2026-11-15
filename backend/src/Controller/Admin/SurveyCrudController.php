<?php

namespace App\Controller\Admin;

use App\Entity\Survey;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\SurveyResponseRepository;
use App\Security\ContentDeleteVoter;
use App\Service\MemberGroup\MemberGroupService;
use App\Service\Survey\SurveySchemaValidator;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * CRUD des sondages. Le schéma des questions (`sections`) est édité en
 * JSON brut dans une textarea (comme le schéma des engagements de charte).
 *
 * Format d'une question :
 *   { "id": "note_seances", "label": "Note ton ressenti", "type": "single_choice",
 *     "required": true, "help": "1 = pas satisfait, 5 = très satisfait",
 *     "options": ["1", "2", "3", "4", "5"],
 *     "groupTarget": { "name": "Stage Banyuls", "trigger": "Oui" } }
 *
 * Types : short_text, long_text, single_choice, multi_choice.
 * `options` requis pour single_choice et multi_choice.
 * `groupTarget` (optionnel, choix unique/multiple seulement) : rattache
 * automatiquement au groupe `name` chaque répondant dont la réponse
 * matche `trigger` — voir MemberGroupService::syncSurveyGroupTargets.
 * Éditable via le builder visuel (case « Rattacher automatiquement… »)
 * sans passer par le JSON brut. Un groupTarget ajouté/modifié sur un
 * sondage qui a déjà des réponses est rejoué immédiatement sur toutes
 * les réponses existantes (backfillGroupTargets, appelé à chaque save).
 */
class SurveyCrudController extends AbstractCrudController
{
    /** Modèle affiché comme placeholder dans la textarea si vide. */
    private const SECTIONS_TEMPLATE = <<<'JSON'
[
  {
    "id": "note_encadrement",
    "label": "Comment évalues-tu l'encadrement ?",
    "type": "single_choice",
    "required": true,
    "options": ["Très bien", "Bien", "Correct", "À améliorer"]
  },
  {
    "id": "themes_stage",
    "label": "Thèmes qui t'intéresseraient pour un futur stage",
    "type": "multi_choice",
    "options": ["Natation", "Vélo", "Course", "Transitions", "Diététique"]
  },
  {
    "id": "commentaire_libre",
    "label": "Un mot pour la fin ?",
    "type": "long_text"
  }
]
JSON;

    use PreselectsStageTrait;

    public function __construct(
        private readonly SurveySchemaValidator $validator,
        private readonly SurveyResponseRepository $responses,
        private readonly MemberGroupService $memberGroups,
        private readonly WebPushNotifier $webPush,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Survey::class;
    }

    public function createEntity(string $entityFqcn): Survey
    {
        // Lien « ➕ Sondage dédié » depuis un stage : le sondage est rattaché d'office à ce stage.
        return (new Survey())->setStage($this->requestedStage());
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('stage', 'Stage'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sondage')
            ->setEntityLabelInPlural('Sondages')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['publishedAt' => 'DESC', 'createdAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['title', 'description']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewResults = Action::new('viewResults', 'Voir les résultats', 'fa fa-chart-bar')
            ->linkToRoute('admin_survey_results', fn (Survey $s) => ['id' => $s->getId()])
            ->displayIf(fn (Survey $s) => $s->getId() !== null);
        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $viewResults)
            ->add(Crud::PAGE_DETAIL, $viewResults)
            ->add(Crud::PAGE_EDIT, $viewResults)
            ->setPermission(Action::DELETE, ContentDeleteVoter::ATTRIBUTE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');

        yield TextEditorField::new('description', 'Introduction (HTML)')
            ->setRequired(false)
            ->setNumOfRows(6)
            ->setHelp('Optionnel — affichée en tête du sondage côté mobile.')
            ->onlyOnForms();

        yield TextareaField::new('sectionsJson', 'Questions')
            ->setNumOfRows(20)
            ->setRequired(false)
            ->setFormTypeOption('mapped', true)
            ->onlyOnForms()
            ->setFormTypeOption('attr', [
                'data-survey-builder' => 'true',
                'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; white-space: pre;',
                'spellcheck' => 'false',
                'placeholder' => self::SECTIONS_TEMPLATE,
            ])
            ->setHelp(
                'Chaque ligne = une question du sondage. Utilisez le builder '
                .'ci-dessous (« Ajouter une question ») ou basculez en JSON brut '
                .'via « ⚙ Éditer le JSON brut ». '
                .'Types disponibles : <strong>Texte court</strong>, '
                .'<strong>Texte long</strong>, <strong>Choix unique</strong>, '
                .'<strong>Choix multiple</strong> (ces 2 derniers demandent des options).'
            );

        yield IntegerField::new('sectionCount', 'Nb questions')
            ->onlyOnIndex()
            ->formatValue(fn ($v, Survey $s) => count($s->getSections() ?? []));

        yield DateTimeField::new('publishedAt', 'Publier à partir de')
            ->setRequired(false)
            ->setHelp('Vide = brouillon (invisible, sauf pour les testeurs ci-dessous). Date passée = actif immédiatement.');

        yield AssociationField::new('testers', '🧪 Testeurs (avant publication)')
            ->setRequired(false)
            ->setFormTypeOption('by_reference', false)
            ->setQueryBuilder(fn ($qb) => $qb
                ->andWhere('entity.isActive = true')
                ->orderBy('entity.nom', 'ASC')
                ->addOrderBy('entity.prenom', 'ASC'))
            ->onlyOnForms()
            ->setHelp('Pour essayer le sondage avant de le publier : choisissez un adhérent cible, ou vous-même. Tant que le sondage n\'est pas publié, il apparaît dans l\'appli de ces seuls testeurs (carte « 🧪 Test », ouverte aussi par une notification) et leurs réponses ne sont PAS enregistrées. Enregistrez pour prévenir les testeurs ajoutés ; une fois le sondage publié, cette liste n\'a plus d\'effet.');

        yield DateTimeField::new('closesAt', 'Fermer le')
            ->setRequired(false)
            ->setHelp('Optionnel. Après cette date, plus de nouvelles réponses acceptées, mais consultation possible.');

        yield AssociationField::new('stage', '🏕️ Stage (sondage dédié)')
            ->setRequired(false)
            ->setQueryBuilder(fn ($qb) => $qb->orderBy('entity.startsOn', 'DESC'))
            ->setHelp('Optionnel. Si renseigné, le sondage est RÉSERVÉ aux participants de ce stage : il n\'apparaît pas dans les sondages généraux, et le ciblage par profil ci-dessous est ignoré. Laisser vide pour un sondage normal.');

        yield ChoiceField::new('audience', 'Audience cible')
            ->setChoices(Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Vide = tous les adhérents. Sinon, uniquement les profils sélectionnés.');

        yield IntegerField::new('responseCount', 'Réponses')
            ->onlyOnIndex()
            ->formatValue(fn ($v, Survey $s) => $this->responses->countForSurvey($s));

        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex();
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Survey) {
            /** @var User $author */
            $author = $this->getUser();
            $entityInstance->setCreatedBy($author);
            $this->validateSchemaOrThrow($entityInstance);
        }
        // Nouveau sondage : tous ses testeurs sont « ajoutés » (collection non encore persistée).
        $added = $entityInstance instanceof Survey ? $entityInstance->getTesters()->toArray() : [];
        parent::persistEntity($em, $entityInstance);

        if ($entityInstance instanceof Survey) {
            $this->notifyTesters($entityInstance, $added);
        }
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $added = [];
        if ($entityInstance instanceof Survey) {
            $entityInstance->touchUpdatedAt();
            $this->validateSchemaOrThrow($entityInstance);
            // Testeurs ajoutés par ce formulaire (à relever AVANT le flush, qui remet le suivi à zéro).
            $testers = $entityInstance->getTesters();
            $added = $testers instanceof PersistentCollection ? $testers->getInsertDiff() : [];
        }
        parent::updateEntity($em, $entityInstance);

        if ($entityInstance instanceof Survey) {
            $this->backfillGroupTargets($em, $entityInstance);
            $this->notifyTesters($entityInstance, $added);
        }
    }

    /**
     * Prévient par notification push les testeurs qui viennent d'être ajoutés, tant que le sondage n'est pas publié :
     * la notification ouvre directement le sondage dans l'appli. Sans effet si le push n'est pas configuré.
     *
     * @param list<User> $added
     */
    private function notifyTesters(Survey $survey, array $added): void
    {
        if ($added === [] || $survey->getId() === null || $survey->isPublished()) {
            return;
        }
        $ids = array_map(static fn (User $u) => (int) $u->getId(), $added);
        $this->webPush->notifyUsers(
            $ids,
            'Sondage à tester',
            sprintf('« %s » n\'est pas encore publié : vous seul le voyez, vos réponses ne seront pas enregistrées.', $survey->getTitle()),
            '/survey/'.$survey->getId(),
            'survey-test-'.$survey->getId(),
        );
        $this->addFlash('success', sprintf('%d testeur(s) ajouté(s) : le sondage apparaît dans leur appli (carte « 🧪 Test ») et une notification leur est envoyée s\'ils ont activé le push.', count($ids)));
    }

    /**
     * Rejoue le rattachement groupTarget (voir MemberGroupService::
     * syncSurveyGroupTargets) sur TOUTES les réponses déjà en base pour
     * ce sondage — sinon un groupTarget ajouté/modifié après coup ne
     * s'appliquerait qu'aux futures soumissions, laissant les réponses
     * existantes hors du groupe. Idempotent, sans effet si aucune
     * question du schéma courant ne déclare de groupTarget.
     */
    private function backfillGroupTargets(EntityManagerInterface $em, Survey $survey): void
    {
        $sections = $survey->getSections() ?? [];
        $hasTarget = false;
        foreach ($sections as $q) {
            if (isset($q['groupTarget'])) { $hasTarget = true; break; }
        }
        if (!$hasTarget) {
            return;
        }

        $responses = $this->responses->findBySurveyWithUser($survey);
        foreach ($responses as $response) {
            $this->memberGroups->syncSurveyGroupTargets($sections, $response->getAnswers(), $response->getUser());
        }
        $em->flush();
    }

    private function validateSchemaOrThrow(Survey $survey): void
    {
        $errors = $this->validator->validateSchema($survey->getSections());
        if ($errors !== []) {
            // On préfère afficher un flash + relancer une InvalidArgumentException
            // pour que EasyAdmin garde le form ouvert avec les messages.
            foreach ($errors as $e) {
                $this->addFlash('danger', $e);
            }
            throw new \InvalidArgumentException(implode(' | ', $errors));
        }
    }
}
