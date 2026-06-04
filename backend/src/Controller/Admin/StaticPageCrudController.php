<?php

namespace App\Controller\Admin;

use App\Entity\StaticPage;
use App\Enum\Profile;
use Doctrine\ORM\EntityRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class StaticPageCrudController extends AbstractCrudController
{
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

        yield IntegerField::new('position', 'Ordre')
            ->setHelp('Plus petit = affiché en premier.')
            ->setColumns(2);

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
        yield DateTimeField::new('updatedAt', 'Mis à jour le')->onlyOnIndex();
    }
}
