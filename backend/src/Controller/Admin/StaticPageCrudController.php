<?php

namespace App\Controller\Admin;

use App\Entity\StaticPage;
use App\Entity\User;
use App\Enum\ContentAudience;
use App\Enum\Profile;
use App\Security\ContentDeleteVoter;
use App\Service\StaticPage\StaticPageAttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;

class StaticPageCrudController extends AbstractCrudController
{
    /** 10 Mo max par PJ — même limite que la page dédiée. */
    private const ATTACHMENT_MAX_BYTES = 10_000_000;

    public function __construct(
        private readonly StaticPageAttachmentService $attachments,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return StaticPage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Page')
            ->setEntityLabelInPlural('Pages')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['parent' => 'ASC', 'position' => 'ASC', 'title' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Bouton « Pièces jointes » : uniquement pour une page déjà
        // enregistrée (l'id est requis par la route).
        $manageAttachments = Action::new('manageAttachments', '📎 Pièces jointes', null)
            ->linkToRoute('admin_static_page_attachments', fn (StaticPage $p) => ['id' => $p->getId()])
            ->displayIf(fn (StaticPage $p) => $p->getId() !== null);

        return parent::configureActions($actions)
            ->add(Crud::PAGE_DETAIL, $manageAttachments)
            ->add(Crud::PAGE_EDIT, $manageAttachments)
            ->setPermission(Action::DELETE, ContentDeleteVoter::ATTRIBUTE);
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        parent::persistEntity($em, $entityInstance);
        $this->processNewAttachments($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        parent::updateEntity($em, $entityInstance);
        $this->processNewAttachments($em, $entityInstance);
    }

    /**
     * Attache les fichiers uploadés (champ non persisté `newAttachments`).
     * Appelé APRÈS le flush parent pour que la page ait un id (requis par
     * StaticPageAttachmentService::upload pour le dossier de stockage).
     */
    private function processNewAttachments(EntityManagerInterface $em, mixed $entity): void
    {
        if (!$entity instanceof StaticPage) {
            return;
        }
        $files = $entity->getNewAttachments();
        $entity->setNewAttachments(null);
        if ($files === null || $files === []) {
            return;
        }
        $rejected = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            if ($file->getSize() > self::ATTACHMENT_MAX_BYTES) {
                $rejected[] = $file->getClientOriginalName();
                continue;
            }
            $this->attachments->upload($entity, $file);
        }
        $em->flush();
        if ($rejected !== []) {
            $this->addFlash('warning', sprintf(
                'Pièce(s) jointe(s) ignorée(s) (>%d Mo) : %s',
                (int) (self::ATTACHMENT_MAX_BYTES / 1_000_000),
                implode(', ', $rejected),
            ));
        }
    }

    public function createEntity(string $entityFqcn): StaticPage
    {
        $page = new StaticPage();
        $user = $this->getUser();
        if ($user instanceof User) {
            $page->setCreatedBy($user);
        }
        return $page;
    }

    /**
     * L'index EasyAdmin (tableau plat) est remplacé par la vue arborescente
     * avec drag-and-drop (admin_pages_reorder). Les actions edit/new/delete
     * du CRUD standard restent accessibles via les liens de cette vue.
     */
    public function index(AdminContext $context)
    {
        return new RedirectResponse($this->generateUrl('admin_pages_reorder'));
    }

    public function configureFields(string $pageName): iterable
    {
        $context = $this->getContext();
        $currentId = null;
        if ($context !== null) {
            $entity = $context->getEntity()->getInstance();
            if ($entity instanceof StaticPage) {
                $currentId = $entity->getId();
            }
        }

        yield TextField::new('title', 'Titre');
        yield TextField::new('slug')
            ->setHelp('Identifiant unique en URL : minuscules, chiffres, tirets. Ex: lieux-rdv');

        yield AssociationField::new('parent', 'Parent')
            ->setRequired(false)
            ->setHelp('Laisser vide pour une page de premier niveau. Choisissez une page parente pour organiser en sous-menu.')
            ->setFormTypeOption('query_builder', function (EntityRepository $er) use ($currentId) {
                $qb = $er->createQueryBuilder('p')->orderBy('p.title', 'ASC');
                if ($currentId !== null) {
                    $qb->andWhere('p.id != :id')->setParameter('id', $currentId);
                }
                return $qb;
            });

        yield TextEditorField::new('content', 'Contenu')
            ->setHelp('Optionnel : peut rester vide si la page sert juste de catégorie regroupant des sous-pages.')
            ->onlyOnForms();

        yield BooleanField::new('isPublished', 'Publié');
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
                'Sans tag = page publique (visible par tous). '
                .'Tag « École de Triathlon » : reste visible par tous, mais devient '
                .'l\'unique catégorie visible pour les comptes Dirigeant.'
            );
        yield DateTimeField::new('updatedAt', 'Mis à jour le')->onlyOnIndex();

        // Upload multi-fichiers, non persisté sur l'entité : traité dans
        // persistEntity/updateEntity (après flush, l'id de la page est requis).
        yield Field::new('newAttachments', '📎 Pièces jointes')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'multiple' => true,
                'required' => false,
                'attr' => ['multiple' => 'multiple'],
            ])
            ->onlyOnForms()
            ->setHelp(
                'PDF, documents, images… — 10 Mo max par fichier. Affichées en bas de la page dans '
                .'l\'appli. Les pièces déjà attachées se gèrent via le bouton « 📎 Pièces jointes » '
                .'en haut à droite (liste + suppression).'
            );
    }
}
