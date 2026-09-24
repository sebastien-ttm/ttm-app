<?php

namespace App\Controller\Admin;

use App\Entity\AdminNotice;
use App\Entity\Article;
use App\Entity\Banner;
use App\Entity\ClubCharter;
use App\Entity\Comment;
use App\Entity\Event;
use App\Entity\EventTag;
use App\Entity\MarketplaceListing;
use App\Entity\MemberGroup;
use App\Entity\MembershipSettings;
use App\Entity\PoolBadge;
use App\Entity\StaticPage;
use App\Entity\Survey;
use App\Entity\TrainingPlan;
use App\Entity\TrainingSeason;
use App\Entity\TrainingSlotTemplate;
use App\Entity\InvoiceSettings;
use App\Entity\MembershipFee;
use App\Entity\User;
use App\Entity\UserMessage;
use App\Entity\WelcomeEmailTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
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
            ->addJsFile('https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js')
            // Éditeur visuel du schéma JSON du formulaire de charte —
            // s'attache automatiquement aux textareas [data-charter-builder].
            // Suffixe basé sur le mtime du fichier pour casser le cache
            // Apache (ExpiresByType application/javascript "access plus 1 year"
            // dans .htaccess) à chaque modification.
            ->addJsFile('js/admin/charter-form-builder.js?v='.$this->assetVersion('public/js/admin/charter-form-builder.js'))
            // Éditeur visuel du schéma JSON des sondages — s'attache aux
            // textareas [data-survey-builder]. Réutilise le CSS du builder
            // charte (classes .cfb-* partagées).
            ->addJsFile('js/admin/survey-form-builder.js?v='.$this->assetVersion('public/js/admin/survey-form-builder.js'))
            ->addCssFile('css/admin/charter-form-builder.css?v='.$this->assetVersion('public/css/admin/charter-form-builder.css'))
            // Repositionne le bouton « Créer … » à gauche sur les pages
            // d'index — évite d'avoir à scroller sur les listes larges.
            ->addCssFile('css/admin/index-actions.css?v='.$this->assetVersion('public/css/admin/index-actions.css'))
            // emoji-picker-element (~50 KB gzipped) : web component
            // <emoji-picker> avec barre de recherche et catégories.
            // Chargé en type=module car c'est un ES module natif.
            ->addJsFile(
                Asset::new('https://cdn.jsdelivr.net/npm/emoji-picker-element@1/index.js')
                    ->htmlAttr('type', 'module')
            )
            // Glue JS : attache un bouton picker à chaque input marqué
            // data-emoji-picker="1" (ex : champ « Icône » des articles).
            ->addJsFile('js/admin/emoji-picker.js?v='.$this->assetVersion('public/js/admin/emoji-picker.js'));
    }

    /**
     * Version d'un asset statique = mtime du fichier (10 chars du hash sha1).
     * Injecté en query string pour forcer le rechargement dans les navigateurs
     * qui ont mis l'asset en cache long grâce aux headers Expires du .htaccess.
     */
    private function assetVersion(string $relativePath): string
    {
        $absolute = \dirname(__DIR__, 3).'/'.$relativePath;
        $mtime = @filemtime($absolute);
        return $mtime !== false ? substr(sha1((string) $mtime), 0, 10) : 'dev';
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
        // Entrées hors-section : accessibles ou masquées par leur propre
        // setPermission() géré par EasyAdmin. Toujours yieldées telles quelles.
        yield AdminMenuItem::linktoDashboard('Accueil', 'fa fa-home');
        yield AdminMenuItem::linkToRoute('Statistiques', 'fa fa-chart-line', 'admin_stats')
            ->setPermission('ROLE_ENTRAINEUR');

        // Structure de menu par section : chaque item porte le rôle
        // Symfony minimal nécessaire pour le voir. Si aucun item d'une
        // section n'est accessible au user courant, l'en-tête de section
        // n'est pas rendu non plus — évite les séparateurs orphelins.
        $sections = [
            'Communication' => [
                ['ROLE_EDITEUR',    fn () => AdminMenuItem::linkToCrud('Articles', 'fa fa-newspaper', Article::class)],
                ['ROLE_EDITEUR',    fn () => AdminMenuItem::linkToCrud('Commentaires', 'fa fa-comments', Comment::class)],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToRoute('Votes de présence', 'fa fa-list-check', 'admin_event_attendance_index')],
                ['ROLE_EDITEUR',    fn () => AdminMenuItem::linkToCrud('Calendrier', 'fa fa-calendar', Event::class)],
                ['ROLE_EDITEUR',    fn () => AdminMenuItem::linkToCrud('Tags d\'événements', 'fa fa-tags', EventTag::class)],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToCrud('Messages reçus', 'fa fa-envelope', UserMessage::class)],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToCrud('Bourse aux équipements', 'fa fa-shirt', MarketplaceListing::class)],
            ],
            'Entraînements' => [
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToRoute('Créneaux de la semaine', 'fa fa-calendar-week', 'admin_training_schedule')],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToCrud('Semaine type', 'fa fa-repeat', TrainingSlotTemplate::class)],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToCrud('Saison d\'entraînement', 'fa fa-calendar-day', TrainingSeason::class)],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToCrud('Plans (PDF)', 'fa fa-file-pdf', TrainingPlan::class)],
            ],
            'Présences staff' => [
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToRoute('Mes présences', 'fa fa-user-check', 'admin_staff_my_presences')],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToRoute('Présences encadrants', 'fa fa-people-group', 'admin_staff_supervision_encadrants')],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToRoute('Emploi du temps entraîneurs', 'fa fa-chalkboard-user', 'admin_staff_supervision_entraineurs')],
                ['ROLE_EDITEUR',    fn () => AdminMenuItem::linkToRoute('Goûters du mercredi', 'fa fa-cookie-bite', 'admin_gouters')],
            ],
            'Configuration' => [
                ['ROLE_EDITEUR', fn () => AdminMenuItem::linkToCrud('Pages statiques', 'fa fa-file-lines', StaticPage::class)],
                ['ROLE_EDITEUR', fn () => AdminMenuItem::linkToCrud('Bannière', 'fa fa-image', Banner::class)],
                ['ROLE_EDITEUR', fn () => AdminMenuItem::linkToCrud('Badge piscines (QR)', 'fa fa-qrcode', PoolBadge::class)],
            ],
            'Adhérents' => [
                ['ROLE_ADMIN',      fn () => AdminMenuItem::linkToCrud('Adhérents', 'fa fa-users', User::class)],
                ['ROLE_ENTRAINEUR', fn () => AdminMenuItem::linkToRoute('Trombinoscope', 'fa fa-address-card', 'admin_members_recap')],
                ['ROLE_ADMIN',      fn () => AdminMenuItem::linkToCrud('Groupes d\'adhérents', 'fa fa-user-group', MemberGroup::class)],
                ['ROLE_ADMIN',      fn () => AdminMenuItem::linkToRoute('Statistiques adhérents', 'fa fa-chart-pie', 'admin_adherents_stats')],
                ['ROLE_ADMIN',      fn () => AdminMenuItem::linkToRoute('Importer un CSV', 'fa fa-file-import', 'admin_csv_import')],
                ['ROLE_ADMIN',      fn () => AdminMenuItem::linkToCrud('Email de bienvenue', 'fa fa-envelope-open-text', WelcomeEmailTemplate::class)],
                ['ROLE_ADMIN',      fn () => AdminMenuItem::linkToCrud('Réglages d\'adhésion', 'fa fa-id-card', MembershipSettings::class)],
            ],
            'Facturation' => [
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToCrud('Grille tarifaire', 'fa fa-euro-sign', MembershipFee::class)],
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToCrud('Paramètres facture', 'fa fa-file-invoice', InvoiceSettings::class)],
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToRoute('Facture famille', 'fa fa-users', 'admin_invoice_family_pick')],
            ],
            'Acceptation' => [
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToCrud('Messages de bienvenue', 'fa fa-file-signature', ClubCharter::class)],
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToCrud('Messages ponctuels', 'fa fa-bullhorn', AdminNotice::class)],
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToCrud('Sondages', 'fa fa-poll', Survey::class)],
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToRoute('Suivi des acceptations', 'fa fa-square-check', 'admin_charter_tracking')],
                ['ROLE_ADMIN', fn () => AdminMenuItem::linkToRoute('Réponses au formulaire', 'fa fa-clipboard-list', 'admin_charter_responses')],
            ],
        ];

        foreach ($sections as $label => $items) {
            $accessible = array_filter($items, fn (array $it) => $this->isGranted($it[0]));
            if (count($accessible) === 0) continue;
            yield AdminMenuItem::section($label);
            foreach ($accessible as [$role, $factory]) {
                yield $factory()->setPermission($role);
            }
        }

        // Séparateur final + lien API (toujours visible pour toute personne
        // autorisée à voir le backend).
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
