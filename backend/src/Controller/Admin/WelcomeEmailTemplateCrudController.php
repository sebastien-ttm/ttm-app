<?php

namespace App\Controller\Admin;

use App\Entity\WelcomeEmailTemplate;
use App\Enum\AdherentKind;
use App\Repository\WelcomeEmailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD des templates email de bienvenue. Plusieurs entrées possibles —
 * une par kind (Tous / Nouveaux / Renouvellements). Le résolveur pioche
 * le kind exact, puis retombe sur `all` si aucun template dédié.
 */
class WelcomeEmailTemplateCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly WelcomeEmailTemplateRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return WelcomeEmailTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Email de bienvenue')
            ->setEntityLabelInPlural('Emails de bienvenue')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['kind' => 'ASC'])
            ->setHelp('index',
                'Un template par cas d\'usage (Tous / Nouveaux / Renouvellements). '
                .'Le résolveur cherche d\'abord le template dédié au cas exact, '
                .'sinon retombe sur celui marqué « Tous ». '
                .'Placeholders : <code>{{ prenom }}</code>, <code>{{ nom }}</code>, <code>{{ magic_link }}</code>.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('kind', 'Destinataires')
            ->setChoices(AdherentKind::choices())
            ->renderAsBadges()
            ->setHelp('Nouveaux = premier import de l\'adhérent. Renouvellements = adhérent déjà connu qui revient. Tous = fallback.');
        yield TextField::new('subject', 'Sujet');
        yield TextEditorField::new('bodyHtml', 'Contenu HTML')
            ->setNumOfRows(25)
            ->onlyOnForms()
            ->setHelp(
                'Personnalisez librement le message. Utilisez les placeholders '
                .'<code>{{ prenom }}</code>, <code>{{ nom }}</code>, <code>{{ magic_link }}</code> '
                .'là où vous voulez les injecter.'
            );
        yield DateTimeField::new('updatedAt', 'Dernière modification')->hideOnForm();
    }
}
