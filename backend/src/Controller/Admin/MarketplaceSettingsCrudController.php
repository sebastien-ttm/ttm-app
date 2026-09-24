<?php

namespace App\Controller\Admin;

use App\Entity\MarketplaceSettings;
use App\Repository\MarketplaceSettingsRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

/**
 * Réglages d'accès à la bourse aux équipements (singleton) : fermée,
 * réservée à quelques comptes testeurs, ou ouverte à tout le club.
 * Remplace l'édition manuelle de MARKETPLACE_TESTER_EMAILS dans .env.local.
 */
class MarketplaceSettingsCrudController extends AbstractCrudController
{
    public function __construct(private readonly MarketplaceSettingsRepository $settings)
    {
    }

    public static function getEntityFqcn(): string
    {
        return MarketplaceSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réglages de la bourse')
            ->setEntityLabelInPlural('Réglages de la bourse')
            ->setEntityPermission('ROLE_ADMIN')
            ->setHelp(Crud::PAGE_INDEX,
                'Qui peut voir et utiliser la bourse aux équipements dans l\'application. '
                .'Une seule configuration active à la fois. Tant qu\'elle n\'a jamais été '
                .'créée, l\'accès dépend de la variable MARKETPLACE_TESTER_EMAILS du serveur.')
            ->setHelp(Crud::PAGE_EDIT,
                'Le changement est immédiat : les comptes concernés voient (ou perdent) '
                .'l\'entrée « Bourse aux équipements » de l\'onglet Club au prochain '
                .'chargement de l\'application.')
            ->setHelp(Crud::PAGE_NEW,
                'Le changement est immédiat : les comptes concernés voient (ou perdent) '
                .'l\'entrée « Bourse aux équipements » de l\'onglet Club au prochain '
                .'chargement de l\'application.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Singleton : pas de suppression, et pas de 2e ligne une fois la
        // première créée (MarketplaceAccess ne lit que la plus ancienne).
        return $actions
            ->disable(Action::DELETE)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->displayIf(
                fn () => $this->settings->findCurrent() === null,
            ));
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('mode', 'Accès à la bourse')
            ->setChoices([
                'Fermée — personne' => MarketplaceSettings::MODE_CLOSED,
                'Phase de test — comptes testeurs uniquement' => MarketplaceSettings::MODE_TESTERS,
                'Ouverte à tout le club' => MarketplaceSettings::MODE_EVERYONE,
            ])
            ->renderExpanded()
            ->setRequired(true);

        yield TextareaField::new('testerEmails', 'Comptes testeurs (e-mails)')
            ->setNumOfRows(6)
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp(
                'Une adresse e-mail par ligne — celle avec laquelle la personne se connecte '
                .'à l\'application. Prise en compte uniquement en mode « comptes testeurs ». '
                .'Un profil enfant qui partage l\'adresse d\'un testeur y a aussi accès.'
            );

        yield IntegerField::new('testerCount', 'Nb testeurs')
            ->onlyOnIndex()
            ->setSortable(false);
    }
}
