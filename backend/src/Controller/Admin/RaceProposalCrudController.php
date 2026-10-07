<?php

namespace App\Controller\Admin;

use App\Entity\RaceProposal;
use App\Enum\RaceType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * Courses proposées par les adhérents (onglet Social côté mobile) :
 * modération (modification, suppression). Pas de création depuis le
 * backend — une proposition a toujours un auteur adhérent. L'auteur et
 * les votes ne sont pas modifiables.
 */
class RaceProposalCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RaceProposal::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Course proposée')
            ->setEntityLabelInPlural('Courses proposées')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['raceDate' => 'DESC'])
            ->setSearchFields(['name', 'author.nom', 'author.prenom']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Course');
        yield DateField::new('raceDate', 'Date')
            ->setFormat('d MMM yyyy');
        // EnumType : le formulaire manipule directement des RaceType
        // (même choix que boardRole dans UserCrudController).
        yield ChoiceField::new('type', 'Type')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class' => RaceType::class,
                'choice_label' => fn (RaceType $t) => $t->label(),
            ])
            ->onlyOnForms();
        yield TextField::new('typeLabel', 'Type')
            ->setSortable(false)
            ->hideOnForm();
        yield AssociationField::new('author', 'Proposée par')
            ->hideOnForm();
        yield BooleanField::new('captain', 'Capitaine')
            ->renderAsSwitch(false)
            ->setHelp('L\'auteur se propose comme capitaine (organise l\'inscription groupée, le déplacement…).');
        yield BooleanField::new('carpoolingEnabled', 'Covoiturage activé')
            ->renderAsSwitch(false)
            ->hideOnIndex();
        yield IntegerField::new('interestedCount', 'Intéressés')
            ->setSortable(false)
            ->hideOnForm();
        yield UrlField::new('url', 'Site')
            ->setRequired(false)
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Proposée le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnIndex()
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof RaceProposal) {
            $entityInstance->touchUpdatedAt();
        }
        parent::updateEntity($entityManager, $entityInstance);
    }
}
