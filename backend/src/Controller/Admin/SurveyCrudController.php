<?php

namespace App\Controller\Admin;

use App\Entity\Survey;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\SurveyResponseRepository;
use App\Service\Survey\SurveySchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD des sondages. Le schéma des questions (`sections`) est édité en
 * JSON brut dans une textarea (comme le schéma des engagements de charte).
 *
 * Format d'une question :
 *   { "id": "note_seances", "label": "Note ton ressenti", "type": "single_choice",
 *     "required": true, "help": "1 = pas satisfait, 5 = très satisfait",
 *     "options": ["1", "2", "3", "4", "5"] }
 *
 * Types : short_text, long_text, single_choice, multi_choice.
 * `options` requis pour single_choice et multi_choice.
 */
class SurveyCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly SurveySchemaValidator $validator,
        private readonly SurveyResponseRepository $responses,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Survey::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sondage')
            ->setEntityLabelInPlural('Sondages')
            ->setEntityPermission('ROLE_ADMIN')
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
            ->add(Crud::PAGE_EDIT, $viewResults);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');

        yield TextEditorField::new('description', 'Introduction (HTML)')
            ->setRequired(false)
            ->setNumOfRows(6)
            ->setHelp('Optionnel — affichée en tête du sondage côté mobile.')
            ->onlyOnForms();

        yield TextareaField::new('sectionsJson', 'Schéma des sections (JSON)')
            ->setNumOfRows(18)
            ->setRequired(false)
            ->setFormTypeOption('mapped', true)
            ->onlyOnForms()
            ->setHelp(
                '<strong>Format :</strong> tableau JSON de questions. '
                .'Chaque question a <code>id</code> (lettres min./chiffres/_), <code>label</code>, '
                .'<code>type</code> (short_text | long_text | single_choice | multi_choice), '
                .'<code>required</code> (bool), <code>help</code> (optionnel). '
                .'<code>options</code> (tableau de chaînes) requis pour single_choice et multi_choice.<br><br>'
                .'<strong>Exemple :</strong><br>'
                .'<pre style="font-size:11px;">[
  {
    "id": "note_encadrement",
    "label": "Comment évalues-tu l\'encadrement ?",
    "type": "single_choice",
    "required": true,
    "options": ["Très bien", "Bien", "Correct", "À améliorer"]
  },
  {
    "id": "themes_stage",
    "label": "Thèmes qui t\'intéresseraient pour un futur stage",
    "type": "multi_choice",
    "options": ["Natation", "Vélo", "Course", "Transitions", "Diététique"]
  },
  {
    "id": "commentaire_libre",
    "label": "Un mot pour la fin ?",
    "type": "long_text"
  }
]</pre>'
            );

        yield IntegerField::new('sectionCount', 'Nb questions')
            ->onlyOnIndex()
            ->formatValue(fn ($v, Survey $s) => count($s->getSections() ?? []));

        yield DateTimeField::new('publishedAt', 'Publier à partir de')
            ->setRequired(false)
            ->setHelp('Vide = brouillon (invisible). Date passée = actif immédiatement.');

        yield DateTimeField::new('closesAt', 'Fermer le')
            ->setRequired(false)
            ->setHelp('Optionnel. Après cette date, plus de nouvelles réponses acceptées, mais consultation possible.');

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
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof Survey) {
            $entityInstance->touchUpdatedAt();
            $this->validateSchemaOrThrow($entityInstance);
        }
        parent::updateEntity($em, $entityInstance);
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
