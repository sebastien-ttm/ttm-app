<?php

namespace App\Controller\Admin;

use App\Entity\MinibusSettings;
use App\Repository\MinibusSettingsRepository;
use App\Security\MinibusVoter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Charte d'utilisation du minibus (singleton), modifiable par le gestionnaire du minibus et les administrateurs. Elle est
 * présentée à l'emprunteur dans l'appli dès que sa demande est validée ; il l'accepte avant de remplir l'état des lieux de
 * départ. La modification est immédiate pour les prochaines acceptations (chaque acceptation garde la version lue).
 */
class MinibusSettingsCrudController extends AbstractCrudController
{
    public function __construct(private readonly MinibusSettingsRepository $settings)
    {
    }

    public static function getEntityFqcn(): string
    {
        return MinibusSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Charte du minibus')
            ->setEntityLabelInPlural('Charte du minibus')
            ->setEntityPermission(MinibusVoter::MANAGE)
            ->setHelp(Crud::PAGE_INDEX, 'Texte de la charte d\'utilisation, présenté à l\'emprunteur une fois sa demande d\'emprunt validée.')
            ->setHelp(Crud::PAGE_EDIT, 'Les emprunteurs voient le nouveau texte dès l\'enregistrement. Les emprunts dont la charte a déjà été acceptée ne sont pas modifiés : l\'acceptation garde la version qui a été lue.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Singleton : pas de suppression, et pas de 2e ligne une fois la première créée (la plus ancienne est lue).
        return $actions
            ->disable(Action::DELETE)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->displayIf(
                fn () => $this->settings->findCurrent() === null,
            ));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('charterTitle', 'Titre');
        yield TextEditorField::new('charterContent', 'Texte de la charte')
            ->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Modifiée le')->hideOnForm();
    }
}
