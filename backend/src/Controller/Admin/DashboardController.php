<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Banner;
use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\MenuItem;
use App\Entity\StaticPage;
use App\Entity\TrainingPlan;
use App\Entity\User;
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

    public function configureMenuItems(): iterable
    {
        yield AdminMenuItem::linktoDashboard('Accueil', 'fa fa-home');

        yield AdminMenuItem::section('Communication');
        yield AdminMenuItem::linkToCrud('Articles', 'fa fa-newspaper', Article::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Commentaires', 'fa fa-comments', Comment::class)
            ->setPermission('ROLE_ADMIN');
        yield AdminMenuItem::linkToCrud('Plans d\'entraînement', 'fa fa-file-pdf', TrainingPlan::class);
        yield AdminMenuItem::linkToCrud('Calendrier', 'fa fa-calendar', Event::class)
            ->setPermission('ROLE_ADMIN');

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
