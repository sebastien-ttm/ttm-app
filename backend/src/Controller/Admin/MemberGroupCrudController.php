<?php

namespace App\Controller\Admin;

use App\Entity\MemberGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD des groupes d'adhérents. Trois usages :
 *  - Création manuelle par un admin (source=manual).
 *  - Vue synoptique des groupes créés automatiquement par un événement
 *    (source=event) ou une réponse de sondage (source=survey).
 *  - Consultation détaillée : bouton « Voir membres » pour lister /
 *    retirer les adhérents rattachés.
 */
class MemberGroupCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MemberGroup::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Groupe d\'adhérents')
            ->setEntityLabelInPlural('Groupes d\'adhérents')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewMembers = Action::new('viewMembers', 'Voir membres', 'fa fa-users')
            ->linkToRoute('admin_member_group_members', fn (MemberGroup $g) => ['id' => $g->getId()])
            ->displayIf(fn (MemberGroup $g) => $g->getId() !== null);

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $viewMembers)
            ->add(Crud::PAGE_DETAIL, $viewMembers)
            ->add(Crud::PAGE_EDIT, $viewMembers);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield AssociationField::new('season', 'Saison');
        yield ChoiceField::new('source', 'Origine')
            ->setChoices([
                'Saisie admin' => MemberGroup::SOURCE_MANUAL,
                'Événement (vote de présence)' => MemberGroup::SOURCE_EVENT,
                'Réponse de sondage' => MemberGroup::SOURCE_SURVEY,
            ])
            ->renderAsBadges()
            ->setDisabled(true)
            ->setHelp('Rempli automatiquement selon le mode de création. Non modifiable à la main.');
        yield IntegerField::new('memberCount', 'Nb adhérents')
            ->onlyOnIndex()
            ->setSortable(false);
        yield AssociationField::new('sourceEvent', 'Événement lié')
            ->setDisabled(true)
            ->hideOnIndex()
            ->setHelp('Renseigné quand le groupe a été créé par un événement à vote de présence.');
        yield TextField::new('sourceSurveyQuestion', 'Question sondage')
            ->setDisabled(true)
            ->hideOnIndex()
            ->setHelp('Question qui a alimenté le groupe (audit).');
        yield TextareaField::new('description', 'Description')
            ->setRequired(false)
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Créé le')
            ->onlyOnIndex();
    }
}
