<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\Profile;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ArticleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Article')
            ->setEntityLabelInPlural('Articles')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC', 'createdAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['title', 'content']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        yield TextEditorField::new('content', 'Contenu')
            ->setNumOfRows(15)
            ->setHelp('Glissez-déposez ou collez une image dans l\'éditeur pour l\'insérer. Cliquez sur l\'image pour la redimensionner.')
            ->onlyOnForms();
        yield AssociationField::new('author', 'Auteur')
            ->setQueryBuilder(fn ($qb) => $qb->andWhere("entity.role = 'admin'"));
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
        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex();
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
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->defaultPublishedAtToNow($entityInstance);
        parent::updateEntity($em, $entityInstance);
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
