<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

/**
 * Vue admin en lecture seule des commentaires — articles ET
 * événements depuis le passage au modèle unifié (Comment.article OU
 * Comment.event, jamais les deux). `parent` signale les réponses
 * threadées (postées par n'importe quel adhérent ou par un admin).
 */
class CommentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Comment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Commentaire')
            ->setEntityLabelInPlural('Commentaires')
            ->setEntityPermission('ROLE_EDITEUR')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('article', 'Article')
            ->setHelp('Renseigné uniquement pour un commentaire d\'article.');
        yield AssociationField::new('event', 'Événement')
            ->setHelp('Renseigné uniquement pour un commentaire d\'événement.');
        yield AssociationField::new('parent', 'Réponse à')
            ->hideOnIndex()
            ->setHelp('Renseigné quand ce commentaire est une réponse threadée à un autre.');
        yield AssociationField::new('user', 'Auteur');
        yield TextareaField::new('content', 'Contenu');
        yield DateTimeField::new('createdAt', 'Posté le');
        yield DateTimeField::new('editedAt', 'Modifié le')
            ->setRequired(false)
            ->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT);
    }
}
