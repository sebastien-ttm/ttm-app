<?php

namespace App\Controller\Admin;

use App\Entity\ClubCharter;
use App\Enum\AdherentKind;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD des ClubCharter : contenu textuel + kind (Nouveaux /
 * Renouvellements / Tous). Les engagements (cases à cocher) ne sont
 * PAS ici — ils vivent dans le singleton CharterEngagementSettings
 * (menu « Engagements »), partagés entre tous les kinds.
 */
class ClubCharterCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ClubCharter::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Formulaire d\'acceptation')
            ->setEntityLabelInPlural('Formulaires d\'acceptation')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->setHelp('index',
                'Le contenu textuel de chaque formulaire peut être personnalisé '
                .'par type d\'adhérent (Nouveaux / Renouvellements / Tous). '
                .'Les <strong>engagements</strong> (cases à cocher) sont communs '
                .'à tous les formulaires et se configurent dans le menu '
                .'« Engagements ».',
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Formulaire d\'acceptation — Saison 2026-2027 »');
        yield TextField::new('version', 'Version / Saison')
            ->setHelp('Identifiant lisible, ex : « 2026-2027 » ou « 2026-2027-rev2 »');
        yield ChoiceField::new('kind', 'Destinataires')
            ->setChoices(AdherentKind::choices())
            ->renderAsBadges()
            ->setHelp(
                'Nouveaux = premier adhérent (aucune adhésion antérieure). '
                .'Renouvellements = adhérent connu qui revient. '
                .'Tous = fallback pour les deux cas si aucun formulaire dédié.'
            );
        yield TextEditorField::new('content', 'Contenu')
            ->setHelp('Texte intégral présenté à l\'adhérent. L\'éditeur supporte les images, listes, mise en forme.')
            ->onlyOnForms();

        yield AssociationField::new('previewUser', 'Aperçu privé')
            ->autocomplete()
            ->setRequired(false)
            ->setHelp(
                'Optionnel. Si renseigné, ce formulaire est visible uniquement '
                .'par cet utilisateur — pratique pour valider le contenu sans '
                .'impacter les autres adhérents. Le formulaire le plus récemment '
                .'publié est présenté à tous les autres.'
            );

        yield DateTimeField::new('publishedAt', 'Publié le')->onlyOnIndex();
    }
}
