<?php

namespace App\Controller\Admin;

use App\Entity\VolunteerPole;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Pôles d'une campagne de bénévolat. Les responsables désignés ici (comptes adhérents) valident les candidatures
 * des fiches du pôle depuis l'appli et reçoivent une notification à chaque nouvelle candidature.
 */
class VolunteerPoleCrudController extends AbstractCrudController
{
    public function __construct(private readonly TrainingSeasonRepository $seasons)
    {
    }

    public static function getEntityFqcn(): string
    {
        return VolunteerPole::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pôle')
            ->setEntityLabelInPlural('Pôles (bénévolat)')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('campaign', 'Événement');
        yield TextField::new('name', 'Pôle');
        yield TextField::new('managerNames', 'Responsables (affichage)')
            ->setRequired(false)
            ->setHelp('Texte affiché sur les fiches, ex. « Pauline & Anne ».');
        $season = $this->seasons->findCurrent();
        yield AssociationField::new('managers', 'Comptes des responsables')
            ->setFormTypeOption('by_reference', false)
            // Liste de choix : uniquement les adhérents de la saison en cours (tous les actifs à défaut de saison).
            ->setQueryBuilder(fn (QueryBuilder $qb) => $season !== null
                ? UserRepository::restrictToSeasonMembers($qb, 'entity', $season)
                : $qb->andWhere('entity.isActive = true')->andWhere("entity.type = 'adherent'"))
            ->autocomplete()
            ->setRequired(false)
            ->setHelp('Ils valident les candidatures du pôle dans l\'appli et sont notifiés à chaque candidature.');
        yield ChoiceField::new('color', 'Couleur')
            ->setChoices(array_combine(VolunteerPole::COLORS, VolunteerPole::COLORS))
            ->hideOnIndex();
        yield IntegerField::new('position', 'Ordre')->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('campaign', 'Événement'));
    }

    public function configureActions(Actions $actions): Actions
    {
        // Pas de création ici : les pôles viennent de la commande d'import (constructeur lié à la campagne).
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW);
    }
}
