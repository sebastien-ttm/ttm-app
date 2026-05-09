<?php

namespace App\Controller\Admin;

use App\Entity\TrainingPlan;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichFileType;

class TrainingPlanCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TrainingPlan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Plan d\'entraînement')
            ->setEntityLabelInPlural('Plans d\'entraînement')
            ->setEntityPermission('ROLE_COACH')
            ->setDefaultSort(['postedAt' => 'DESC'])
            ->setSearchFields(['title', 'description']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        yield TextareaField::new('description')->setRequired(false);
        yield DateField::new('weekStartsAt', 'Semaine du')
            ->setRequired(false)
            ->setHelp('Lundi de la semaine concernée.');
        yield Field::new('file', 'Fichier PDF')
            ->setFormType(VichFileType::class)
            ->setFormTypeOptions(['allow_delete' => false, 'download_uri' => false])
            ->onlyOnForms();
        yield TextField::new('filePath', 'Fichier')->onlyOnIndex();
        yield AssociationField::new('postedBy', 'Posté par')->onlyOnDetail();
        yield DateTimeField::new('postedAt', 'Posté le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): TrainingPlan
    {
        $plan = new TrainingPlan();
        $user = $this->getUser();
        if ($user instanceof User) {
            $plan->setPostedBy($user);
        }
        return $plan;
    }
}
