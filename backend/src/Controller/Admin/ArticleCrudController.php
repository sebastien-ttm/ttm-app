<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\ContentAudience;
use App\Enum\Profile;
use App\Service\Article\ArticleAttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
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

class ArticleCrudController extends AbstractCrudController
{
    /** 10 Mo max par PJ — même limite que la page dédiée. */
    private const ATTACHMENT_MAX_BYTES = 10_000_000;

    public function __construct(
        private readonly ArticleAttachmentService $attachments,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Article')
            ->setEntityLabelInPlural('Articles')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['publishedAt' => 'DESC', 'createdAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['title', 'content']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Bouton « Pièces jointes » sur l'index et le détail — accessible
        // uniquement pour un article déjà persisté (l'id est requis pour
        // la route d'upload).
        $manageAttachments = Action::new('manageAttachments', '📎 Pièces jointes', null)
            ->linkToRoute('admin_article_attachments', fn (Article $a) => ['id' => $a->getId()])
            ->displayIf(fn (Article $a) => $a->getId() !== null);

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $manageAttachments)
            ->add(Crud::PAGE_DETAIL, $manageAttachments)
            ->add(Crud::PAGE_EDIT, $manageAttachments);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        yield TextEditorField::new('content', 'Contenu')
            ->setNumOfRows(15)
            ->setHelp('Glissez-déposez ou collez une image dans l\'éditeur pour l\'insérer. Cliquez sur l\'image pour la redimensionner.')
            ->onlyOnForms();
        yield AssociationField::new('author', 'Auteur')
            ->setQueryBuilder(fn ($qb) => $qb->andWhere("entity.role IN ('editeur', 'entraineur', 'admin')"));
        yield DateTimeField::new('publishedAt', 'Publication')
            ->setRequired(false)
            ->setHelp('Laisser vide = publier immédiatement. Date future = publication programmée.');
        yield BooleanField::new('notifyOnPublish', 'Notification push à la publication')
            ->onlyOnForms();
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
                'Sans tag = contenu public (visible par tous). '
                .'Tag « École de Triathlon » : reste visible par tous, mais devient '
                .'l\'unique catégorie visible pour les comptes Dirigeant.'
            );
        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex();

        // Upload multi-fichiers, non persisté sur l'entité : le contrôleur
        // traite les fichiers dans persistEntity/updateEntity (après flush,
        // pour disposer de l'id de l'article requis par le service).
        yield Field::new('newAttachments', '📎 Pièces jointes')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'multiple' => true,
                'required' => false,
                'attr' => ['multiple' => 'multiple'],
            ])
            ->onlyOnForms()
            ->setHelp(
                'PDF, GPX, documents, images additionnelles… — 10 Mo max par fichier. '
                .'Les pièces déjà attachées se gèrent via le bouton « 📎 Pièces jointes » '
                .'en haut à droite (liste + suppression).'
            );
    }

    public function createEntity(string $entityFqcn): Article
    {
        $article = new Article();
        $user = $this->getUser();
        if ($user instanceof User) {
            $article->setAuthor($user);
        }
        return $article;
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->defaultPublishedAtToNow($entityInstance);
        parent::persistEntity($em, $entityInstance);
        $this->processNewAttachments($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->defaultPublishedAtToNow($entityInstance);
        parent::updateEntity($em, $entityInstance);
        $this->processNewAttachments($em, $entityInstance);
    }

    /**
     * Attache les fichiers uploadés (champ non persisté `newAttachments`).
     * Appelé APRÈS le flush parent pour que l'article ait un id (requis par
     * ArticleAttachmentService::upload pour le dossier de stockage).
     */
    private function processNewAttachments(EntityManagerInterface $em, mixed $entity): void
    {
        if (!$entity instanceof Article) {
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

    /**
     * Si l'admin laisse la date de publication vide, on considère que
     * l'article est publié immédiatement (au lieu de rester brouillon).
     */
    private function defaultPublishedAtToNow(mixed $entity): void
    {
        if ($entity instanceof Article && $entity->getPublishedAt() === null) {
            $entity->setPublishedAt(new \DateTimeImmutable());
        }
    }
}
