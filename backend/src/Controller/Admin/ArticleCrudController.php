<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\ArticlePhoto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Vich\UploaderBundle\Form\Type\VichImageType;

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
            ->setSearchFields(['title', 'content']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addTab('Article');
        yield TextField::new('title', 'Titre');
        yield TextEditorField::new('content', 'Contenu')
            ->setNumOfRows(15)
            ->onlyOnForms();
        yield AssociationField::new('author', 'Auteur')
            ->setQueryBuilder(fn ($qb) => $qb->andWhere('entity.roles LIKE :r')->setParameter('r', '%ROLE_ADMIN%'));
        yield DateTimeField::new('publishedAt', 'Publication')
            ->setHelp('Vide = brouillon. Date passée = publié immédiatement.');
        yield BooleanField::new('notifyOnPublish', 'Notification push à la publication')
            ->onlyOnForms();
        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex();

        yield FormField::addTab('Photos')->onlyOnForms();
        yield CollectionField::new('photos', 'Photos')
            ->setEntryIsComplex()
            ->useEntryCrudForm(ArticlePhotoCrudController::class)
            ->onlyOnForms();
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
}
