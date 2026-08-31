<?php

namespace App\Controller\Admin;

use App\Entity\ClubCharter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD des ClubCharter : contenu textuel du formulaire d'acceptation,
 * versionné par saison (le plus récent est actif pour tout le monde).
 * Les engagements (cases à cocher) sont partagés dans le singleton
 * CharterEngagementSettings (menu « Engagements »).
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
            ->setEntityLabelInSingular('Message de bienvenue')
            ->setEntityLabelInPlural('Messages de bienvenue')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->setHelp('index',
                'Le message actif est le plus récent publié — tous les '
                .'adhérents (nouveaux comme renouvellements) voient le même. '
                .'La liste conserve l\'historique par saison. Les '
                .'<strong>engagements</strong> (cases à cocher) se configurent '
                .'dans le menu « Engagements ».',
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Message de bienvenue — Saison 2026-2027 »');
        yield TextField::new('version', 'Version / Saison')
            ->setHelp('Identifiant lisible, ex : « 2026-2027 » ou « 2026-2027-rev2 »');
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
