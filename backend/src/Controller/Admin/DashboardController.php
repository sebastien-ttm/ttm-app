<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Banner;
use App\Entity\ClubCharter;
use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\MembershipSettings;
use App\Entity\MenuItem;
use App\Entity\StaticPage;
use App\Entity\TrainingPlan;
use App\Entity\TrainingSeason;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem as AdminMenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(): Response
    {
        return $this->render('admin/welcome.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/img/logo_text.svg" style="max-height: 40px;" alt="TTM">')
            ->setFaviconPath('img/logo.svg')
            ->setLocales(['fr']);
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            // TinyMCE 7 (community / GPL) loaded from jsDelivr — includes
            // image plugin with native resize handles. Init lives in the
            // form_theme override.
            ->addJsFile('https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js');
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            // Order matters: the LAST theme has the highest priority for any
            // block it defines, so EasyAdmin's default goes first and our
            // override goes last to win on `ea_text_editor_widget`.
            ->setFormThemes([
                '@EasyAdmin/crud/form_theme.html.twig',
                'admin/form_theme.html.twig',
            ]);
    }

    public function configureMenuItems(): iterable
    {
        yield AdminMenuItem::linktoDashboard('Accueil', 'fa fa-home');

        yield AdminMenuItem::section('Communication');
        yield AdminMenuItem::linkToCrud('Articles', 'fa fa-newspaper', Article::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Commentaires', 'fa fa-comments', Comment::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Calendrier', 'fa fa-calendar', Event::class)
            ->setPermission('ROLE_ADMIN');

        yield AdminMenuItem::section('Entraînements');
        yield AdminMenuItem::linkToRoute('Créneaux de la semaine', 'fa fa-calendar-week', 'admin_training_schedule')
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Semaine type', 'fa fa-repeat', TrainingSlotTemplate::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Saison d\'entraînement', 'fa fa-calendar-day', TrainingSeason::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Plans (PDF)', 'fa fa-file-pdf', TrainingPlan::class);

        yield AdminMenuItem::section('Configuration');
        yield AdminMenuItem::linkToCrud('Pages statiques', 'fa fa-file-lines', StaticPage::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Menu mobile', 'fa fa-bars', MenuItem::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Bannière', 'fa fa-image', Banner::class)
            ->setPermission('ROLE_ADMIN');

        yield AdminMenuItem::section('Adhérents');
        yield AdminMenuItem::linkToCrud('Adhérents', 'fa fa-users', User::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToRoute('Importer un CSV', 'fa fa-file-import', 'admin_csv_import')
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Réglages d\'adhésion', 'fa fa-id-card', MembershipSettings::class)
            ->setPermission('ROLE_ADMIN');

        yield AdminMenuItem::section('Charte');
        yield AdminMenuItem::linkToCrud('Charte du club', 'fa fa-file-signature', ClubCharter::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToRoute('Suivi des acceptations', 'fa fa-list-check', 'admin_charter_tracking')
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToRoute('Réponses au formulaire', 'fa fa-clipboard-list', 'admin_charter_responses')
            ->setPermission('ROLE_ADMIN');

        yield AdminMenuItem::section();
        yield AdminMenuItem::linkToRoute('Voir l\'API', 'fa fa-book', 'api_doc');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $name = $user instanceof \App\Entity\User ? $user->getFullName() : $user->getUserIdentifier();
        return parent::configureUserMenu($user)
            ->setName($name)
            ->displayUserAvatar(false);
    }
}
