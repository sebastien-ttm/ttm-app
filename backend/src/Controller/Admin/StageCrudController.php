<?php

namespace App\Controller\Admin;

use App\Entity\Stage;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\LoggerInterface;

/**
 * Stages du club : le backend crée le stage, choisit ses participants et y rattache ses éléments dédiés (événements,
 * articles, sondages, prises de temps, entraînements) ; les participants retrouvent le tout dans l'appli, dans une vue
 * de contexte dédiée. Les éléments dédiés ne figurent nulle part ailleurs dans l'appli.
 */
class StageCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly WebPushNotifier $webPush,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Stage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Stage')
            ->setEntityLabelInPlural('Stages')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['startsOn' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['title', 'location'])
            ->setHelp(Crud::PAGE_INDEX, 'Un stage regroupe ses participants et ses éléments dédiés (événements, articles, sondages, prises de temps, entraînements). Ouvrez un stage, puis utilisez les boutons « ➕ … dédié » pour créer ses éléments : ils n\'apparaissent que dans la vue du stage, jamais dans les listes générales.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Création d'un élément dédié : le formulaire s'ouvre avec le stage déjà choisi (?stageId=…).
        $create = fn (string $name, string $label, string $crud) => Action::new($name, $label, null)
            ->linkToUrl(fn (Stage $s) => $this->adminUrlGenerator->unsetAll()
                ->setController($crud)
                ->setAction(Action::NEW)
                ->set('stageId', $s->getId())
                ->generateUrl())
            ->displayIf(fn (Stage $s) => $s->getId() !== null);
        // Liste des éléments dédiés d'un type : la liste générale du type, filtrée sur ce stage.
        $list = fn (string $name, string $label, string $crud) => Action::new($name, $label, null)
            ->linkToUrl(fn (Stage $s) => $this->adminUrlGenerator->unsetAll()
                ->setController($crud)
                ->setAction(Action::INDEX)
                ->set('filters', ['stage' => ['comparison' => '=', 'value' => $s->getId()]])
                ->generateUrl())
            ->displayIf(fn (Stage $s) => $s->getId() !== null);

        $newEvent = $create('newStageEvent', '➕ Événement dédié', EventCrudController::class);
        $newArticle = $create('newStageArticle', '➕ Article dédié', ArticleCrudController::class);
        $newSurvey = $create('newStageSurvey', '➕ Sondage dédié', SurveyCrudController::class);
        $listEvents = $list('listStageEvents', '📅 Événements', EventCrudController::class);
        $listArticles = $list('listStageArticles', '📰 Articles', ArticleCrudController::class);
        $listSurveys = $list('listStageSurveys', '📊 Sondages', SurveyCrudController::class);

        // Prises de temps et entraînements : réservés aux entraîneurs (comme leurs écrans de gestion).
        $newPerf = $create('newStagePerfTest', '➕ Prise de temps dédiée', PerfTestSessionCrudController::class)
            ->displayIf(fn (Stage $s) => $s->getId() !== null && $this->isGranted('ROLE_ENTRAINEUR'));
        $listPerf = $list('listStagePerfTests', '⏱ Prises de temps', PerfTestSessionCrudController::class)
            ->displayIf(fn (Stage $s) => $s->getId() !== null && $this->isGranted('ROLE_ENTRAINEUR'));
        // Entraînement : le créneau occasionnel se crée depuis l'écran « Créneaux de la semaine » (date proposée = début
        // du stage). URL directe (et non linkToRoute) : EasyAdmin rangerait stageId / date dans `routeParams`, que cet
        // écran ne lit pas — il ouvrirait alors la semaine en cours, sans lien avec le stage.
        $newTraining = Action::new('newStageTraining', '➕ Entraînement dédié', null)
            ->linkToUrl(fn (Stage $s) => $this->generateUrl('admin_training_schedule_edit', [
                'stageId' => $s->getId(),
                'date' => $s->getStartsOn()?->format('Y-m-d'),
            ]))
            ->displayIf(fn (Stage $s) => $s->getId() !== null && $this->isGranted('ROLE_ENTRAINEUR'));
        // Créneaux de la semaine de début du stage ; la navigation par semaine de l'écran mène aux suivantes.
        $listTraining = Action::new('listStageTrainings', '🏊 Entraînements', null)
            ->linkToUrl(fn (Stage $s) => $this->generateUrl('admin_training_schedule', [
                'week' => $s->getStartsOn()?->format('Y-m-d'),
            ]))
            ->displayIf(fn (Stage $s) => $s->getId() !== null && $this->isGranted('ROLE_ENTRAINEUR'));

        // Un stage qui a des éléments dédiés ne se supprime pas (ils redeviendraient publics) : il faut d'abord
        // supprimer ces éléments.
        $deletable = fn (Action $a) => $a->displayIf(fn (Stage $s) => !$s->hasDedicatedContent());

        $actions = parent::configureActions($actions);
        foreach ([$newEvent, $newArticle, $newSurvey, $newPerf, $newTraining] as $a) {
            $actions->add(Crud::PAGE_DETAIL, $a)->add(Crud::PAGE_EDIT, $a);
        }
        foreach ([$listEvents, $listArticles, $listSurveys, $listPerf, $listTraining] as $a) {
            $actions->add(Crud::PAGE_DETAIL, $a);
        }

        return $actions
            ->update(Crud::PAGE_INDEX, Action::DELETE, $deletable)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $deletable);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        // Même picker d'emoji que les articles (data-emoji-picker, cf. js/admin/emoji-picker.js).
        yield TextField::new('icon', 'Icône (emoji)')
            ->setRequired(false)
            ->setFormTypeOption('attr', [
                'data-emoji-picker' => '1',
                'maxlength' => '32',
                'placeholder' => 'ex : 🏕️',
                'autocomplete' => 'off',
            ])
            ->setHelp('Affiché devant le titre dans l\'appli. Cliquez sur 😀 pour ouvrir le picker.')
            ->hideOnIndex();
        yield TextField::new('location', 'Lieu')
            ->setRequired(false);
        yield DateField::new('startsOn', 'Début');
        yield DateField::new('endsOn', 'Fin')
            ->setRequired(false)
            ->setHelp('Dernier jour du stage. Laisser vide pour un stage d\'une seule journée.');
        yield BooleanField::new('published', 'Publié')
            ->setHelp('Tant que le stage n\'est pas publié (brouillon), personne ne le voit dans l\'appli, ni aucun de ses éléments dédiés. À la publication, les participants reçoivent une notification.');
        yield AssociationField::new('participants', 'Participants')
            ->setRequired(false)
            ->setFormTypeOption('by_reference', false)
            ->setQueryBuilder(fn ($qb) => $qb
                ->andWhere('entity.isActive = true')
                ->orderBy('entity.nom', 'ASC')
                ->addOrderBy('entity.prenom', 'ASC'))
            ->setHelp('Seuls ces adhérents voient le stage et tous ses éléments dédiés dans leur espace « Stage » de l\'appli. Recherche par nom ou numéro de licence.');

        yield FormField::addPanel('Informations pratiques')->onlyOnForms();
        yield TextEditorField::new('description', 'Informations pratiques')
            ->setNumOfRows(14)
            ->setRequired(false)
            ->setHelp('Programme, hébergement, rendez-vous, matériel à prévoir… Glissez-déposez ou collez une image pour l\'insérer. Les événements, articles, sondages, prises de temps et entraînements se créent depuis les boutons « ➕ … dédié » de la page du stage (une fois celui-ci enregistré).')
            ->onlyOnForms();

        // Nombre d'éléments dédiés (détail uniquement) ; la liste de chaque type est accessible par les boutons du détail.
        yield AssociationField::new('events', '📅 Événements dédiés')->onlyOnDetail();
        yield AssociationField::new('articles', '📰 Articles dédiés')->onlyOnDetail();
        yield AssociationField::new('surveys', '📊 Sondages dédiés')->onlyOnDetail();
        yield AssociationField::new('perfTestSessions', '⏱ Prises de temps dédiées')->onlyOnDetail();
        yield AssociationField::new('trainingSlots', '🏊 Entraînements dédiés')->onlyOnDetail();
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        parent::persistEntity($em, $entityInstance);
        $this->notifyNewParticipants($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        parent::updateEntity($em, $entityInstance);
        $this->notifyNewParticipants($em, $entityInstance);
    }

    /**
     * Prévient par notification push les participants d'un stage PUBLIÉ qui ne l'ont pas encore été (une seule fois
     * chacun, tracé dans notifiedUserIds). Best-effort : un échec du push ne doit jamais bloquer l'enregistrement.
     */
    private function notifyNewParticipants(EntityManagerInterface $em, mixed $entity): void
    {
        if (!$entity instanceof Stage || !$entity->isPublished()) {
            return;
        }

        $already = array_flip($entity->getNotifiedUserIds());
        $toNotify = [];
        foreach ($entity->getParticipants() as $user) {
            if ($user->isActive() && !isset($already[$user->getId()])) {
                $toNotify[] = (int) $user->getId();
            }
        }
        if ($toNotify === []) {
            return;
        }

        try {
            $when = $entity->getStartsOn()?->format('d/m/Y') ?? '';
            if ($entity->getEndsOn() !== null && $entity->getEndsOn() != $entity->getStartsOn()) {
                $when .= ' → '.$entity->getEndsOn()->format('d/m/Y');
            }
            $body = trim($when.($entity->getLocation() !== null ? ' · '.$entity->getLocation() : ''));
            $this->webPush->notifyUsers(
                $toNotify,
                'Vous participez à un stage',
                trim(($entity->getIcon() !== null ? $entity->getIcon().' ' : '').$entity->getTitle().($body !== '' ? ' — '.$body : '')),
                '/stage/'.$entity->getId(),
                'stage-'.$entity->getId(),
            );
            $entity->setNotifiedUserIds(array_merge($entity->getNotifiedUserIds(), $toNotify));
            $em->flush();
            $this->addFlash('success', sprintf('%d participant(s) prévenu(s) par notification (ceux qui ont activé les notifications dans l\'appli).', count($toNotify)));
        } catch (\Throwable $e) {
            $this->logger->warning('Notification de stage impossible', ['stageId' => $entity->getId(), 'error' => $e->getMessage()]);
        }
    }
}
