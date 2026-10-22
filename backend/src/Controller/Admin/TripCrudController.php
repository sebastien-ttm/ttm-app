<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\Trip;
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
 * Déplacements du club : le backend crée le déplacement, choisit ses participants, rattache des événements du
 * calendrier et des articles dédiés ; les participants le retrouvent dans l'appli (vue de contexte dédiée).
 */
class TripCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly WebPushNotifier $webPush,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Trip::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Déplacement')
            ->setEntityLabelInPlural('Déplacements')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['startsOn' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['title', 'location']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Articles dédiés : réservés aux participants, absents du fil général. On les crée / consulte depuis le
        // déplacement (l'article est alors rattaché d'office).
        $newArticle = Action::new('newTripArticle', '➕ Article dédié', null)
            ->linkToUrl(fn (Trip $t) => $this->adminUrlGenerator->unsetAll()
                ->setController(ArticleCrudController::class)
                ->setAction(Action::NEW)
                ->set('tripId', $t->getId())
                ->generateUrl())
            ->displayIf(fn (Trip $t) => $t->getId() !== null);
        $listArticles = Action::new('listTripArticles', '📰 Articles dédiés', null)
            ->linkToUrl(fn (Trip $t) => $this->adminUrlGenerator->unsetAll()
                ->setController(ArticleCrudController::class)
                ->setAction(Action::INDEX)
                ->set('filters', ['trip' => ['comparison' => '=', 'value' => $t->getId()]])
                ->generateUrl())
            ->displayIf(fn (Trip $t) => $t->getId() !== null);

        // Un déplacement qui a des articles dédiés ne se supprime pas (ils redeviendraient publics) : il faut d'abord
        // supprimer ou détacher ces articles.
        $deletable = fn (Action $a) => $a->displayIf(fn (Trip $t) => $t->getArticles()->isEmpty());

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $newArticle)
            ->add(Crud::PAGE_INDEX, $listArticles)
            ->add(Crud::PAGE_DETAIL, $newArticle)
            ->add(Crud::PAGE_DETAIL, $listArticles)
            ->add(Crud::PAGE_EDIT, $newArticle)
            ->add(Crud::PAGE_EDIT, $listArticles)
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
                'placeholder' => 'ex : ✈️',
                'autocomplete' => 'off',
            ])
            ->setHelp('Affiché devant le titre dans l\'appli. Cliquez sur 😀 pour ouvrir le picker.')
            ->hideOnIndex();
        yield TextField::new('location', 'Lieu')
            ->setRequired(false);
        yield DateField::new('startsOn', 'Départ');
        yield DateField::new('endsOn', 'Retour')
            ->setRequired(false)
            ->setHelp('Dernier jour du déplacement. Laisser vide pour un déplacement d\'une seule journée.');
        yield BooleanField::new('published', 'Publié')
            ->setHelp('Tant que le déplacement n\'est pas publié (brouillon), personne ne le voit dans l\'appli. À la publication, les participants reçoivent une notification.');
        yield AssociationField::new('participants', 'Participants')
            ->setRequired(false)
            ->setFormTypeOption('by_reference', false)
            ->setQueryBuilder(fn ($qb) => $qb
                ->andWhere('entity.isActive = true')
                ->orderBy('entity.nom', 'ASC')
                ->addOrderBy('entity.prenom', 'ASC'))
            ->setHelp('Seuls ces adhérents voient le déplacement, ses articles dédiés et ses événements dans leur espace « Déplacement » de l\'appli. Recherche par nom ou numéro de licence.');

        yield FormField::addPanel('Contenu du déplacement')->onlyOnForms();
        yield TextEditorField::new('description', 'Informations pratiques')
            ->setNumOfRows(14)
            ->setRequired(false)
            ->setHelp('Programme, hébergement, rendez-vous, matériel à prévoir… Glissez-déposez ou collez une image pour l\'insérer.')
            ->onlyOnForms();
        // Événements du calendrier : ils restent des événements normaux (visibles de leur audience dans le
        // calendrier) ; le déplacement les regroupe pour ses participants.
        yield AssociationField::new('events', '📅 Événements du calendrier')
            ->setRequired(false)
            ->setFormTypeOptions([
                'by_reference' => false,
                'choice_label' => fn (Event $e) => sprintf(
                    '%s — %s',
                    $e->getStartsAt()->format('d/m/Y'),
                    $e->getTitle(),
                ),
            ])
            ->setQueryBuilder(fn ($qb) => $qb->orderBy('entity.startsAt', 'DESC'))
            ->setHelp('Événements déjà créés dans le calendrier (étapes, courses, repas…). Ils s\'affichent dans la vue du déplacement avec leur vote de présence. Pour un événement réservé au déplacement, créez-le avec une audience restreinte dans « Calendrier ».')
            ->onlyOnForms();
        yield AssociationField::new('articles', '📰 Articles dédiés')
            ->setHelp('Articles réservés aux participants : utilisez le bouton « ➕ Article dédié » (le déplacement doit être enregistré).')
            ->hideOnForm();
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
     * Prévient par notification push les participants d'un déplacement PUBLIÉ qui ne l'ont pas encore été (une seule
     * fois chacun, tracé dans notifiedUserIds). Best-effort : un échec du push ne doit jamais bloquer l'enregistrement.
     */
    private function notifyNewParticipants(EntityManagerInterface $em, mixed $entity): void
    {
        if (!$entity instanceof Trip || !$entity->isPublished()) {
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
                'Vous participez à un déplacement',
                trim(($entity->getIcon() !== null ? $entity->getIcon().' ' : '').$entity->getTitle().($body !== '' ? ' — '.$body : '')),
                '/trip/'.$entity->getId(),
                'trip-'.$entity->getId(),
            );
            $entity->setNotifiedUserIds(array_merge($entity->getNotifiedUserIds(), $toNotify));
            $em->flush();
            $this->addFlash('success', sprintf('%d participant(s) prévenu(s) par notification (ceux qui ont activé les notifications dans l\'appli).', count($toNotify)));
        } catch (\Throwable $e) {
            $this->logger->warning('Notification de déplacement impossible', ['tripId' => $entity->getId(), 'error' => $e->getMessage()]);
        }
    }
}
