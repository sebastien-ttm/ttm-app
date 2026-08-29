<?php

namespace App\Controller\Admin;

use App\Entity\WelcomeEmailTemplate;
use App\Repository\WelcomeEmailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * CRUD singleton : une seule ligne WelcomeEmailTemplate. On force
 * l'index/new à rediriger vers l'édition de la ligne existante (ou
 * à la créer si absente).
 */
class WelcomeEmailTemplateCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly WelcomeEmailTemplateRepository $repo,
        private readonly AdminUrlGenerator $adminUrlGenerator,
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
            ->setEntityLabelInPlural('Email de bienvenue')
            ->setEntityPermission('ROLE_ADMIN')
            ->setPageTitle('edit', '📧 Email de bienvenue (nouvel adhérent)')
            ->setHelp('edit',
                'Ce message est envoyé automatiquement à chaque nouvel adhérent créé '
                .'par l\'import CSV FFTri. Il inclut son lien de première connexion. '
                .'Placeholders disponibles dans le sujet et le corps : '
                .'<code>{{ prenom }}</code>, <code>{{ nom }}</code>, <code>{{ magic_link }}</code>.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        // Retire l'action « supprimer » et « nouveau » — le modèle est
        // singleton (une seule ligne, jamais effaçable ni dupliquée).
        return parent::configureActions($actions)
            ->disable(Action::DELETE, Action::NEW, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('subject', 'Sujet');
        yield TextEditorField::new('bodyHtml', 'Contenu HTML')
            ->setNumOfRows(25)
            ->onlyOnForms()
            ->setHelp(
                'Personnalisez librement le message. Utilisez les placeholders '
                .'<code>{{ prenom }}</code>, <code>{{ nom }}</code>, <code>{{ magic_link }}</code> '
                .'là où vous voulez les injecter. Le bouton de connexion se fait typiquement en '
                .'entourant l\'URL : <code>&lt;a href="{{ magic_link }}"&gt;Se connecter&lt;/a&gt;</code>.'
            );
        yield DateTimeField::new('updatedAt', 'Dernière modification')->hideOnForm();
    }

    /**
     * Redirige l'index (désactivé) vers l'édition de la ligne singleton.
     * Crée la ligne à la volée si absente.
     */
    public function index(AdminContext $context)
    {
        $template = $this->repo->findCurrent();
        if ($template === null) {
            // Filet : la migration seed déjà une ligne, mais si elle a été
            // supprimée en base à la main, on la recrée à la volée.
            $template = new WelcomeEmailTemplate();
            $this->em->persist($template);
            $this->em->flush();
        }
        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($template->getId())
            ->generateUrl();
        return new RedirectResponse($url);
    }
}
