<?php

namespace App\DataFixtures;

use App\Entity\Article;
use App\Entity\Banner;
use App\Entity\Event;
use App\Entity\MenuItem;
use App\Entity\StaticPage;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\MenuItemType;
use App\Enum\Profile;
use App\Enum\UserType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $projectDir,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // -- Users --
        $admin = $this->makeUser('A0001', 'admin@ttm.test', 'Admin', 'TTM', 'admin', [Profile::Senior], 'demo');
        $coach = $this->makeUser('C0001', 'coach@ttm.test', 'Coach', 'TTM', 'admin', [Profile::Senior, Profile::Entraineur], 'demo');
        $u1 = $this->makeUser('U0001', 'paul@ttm.test', 'Paul', 'Durand', 'user', [Profile::Senior], 'demo');
        $u2 = $this->makeUser('U0002', 'marie@ttm.test', 'Marie', 'Lefèvre', 'user', [Profile::Senior], 'demo');
        $u3 = $this->makeUser('U0003', 'lucas@ttm.test', 'Lucas', 'Bernard', 'user', [Profile::Jeune], 'demo');

        $manager->persist($admin);
        $manager->persist($coach);
        $manager->persist($u1);
        $manager->persist($u2);
        $manager->persist($u3);

        // -- Banner (default seasonal banner) --
        $defaultBanner = $this->projectDir.'/public/img/banner-default.jpg';
        $banner = new Banner();
        if (is_file($defaultBanner)) {
            $bannerDir = $this->projectDir.'/public/uploads/banners';
            if (!is_dir($bannerDir)) {
                @mkdir($bannerDir, 0775, true);
            }
            $bannerName = 'banner-saison-'.date('Y').'.jpg';
            @copy($defaultBanner, $bannerDir.'/'.$bannerName);
            $banner->setImagePath($bannerName);
        }
        $banner->setTitle('Saison '.date('Y').' — Triathlon Toulouse Métropole');
        $banner->setIsActive(true);
        $manager->persist($banner);

        // -- Articles --
        $a1 = (new Article())
            ->setTitle('Bienvenue sur l\'application du club !')
            ->setContent('<p>Cette application est votre nouveau lien direct avec le club. Vous y retrouverez :</p><ul><li>Les actualités et événements</li><li>Les plans d\'entraînement de la semaine</li><li>Les pages utiles (lieux de RDV, partenaires, bureau)</li></ul><p>N\'hésitez pas à <strong>réagir</strong> et <strong>commenter</strong> sous les articles !</p>')
            ->setAuthor($admin)
            ->setPublishedAt(new \DateTimeImmutable('-2 days'));
        $manager->persist($a1);

        $a2 = (new Article())
            ->setTitle('Stage de pré-saison — inscriptions ouvertes')
            ->setContent('<p>Le stage annuel se tiendra du <strong>15 au 18 mars</strong> à Banyuls. Au programme :</p><ul><li>Sorties vélo dans les Albères</li><li>Natation en piscine + en mer</li><li>Footings côtiers</li></ul><p>Inscription auprès du bureau avant le 28 février. Tarif : 320 €.</p>')
            ->setAuthor($admin)
            ->setPublishedAt(new \DateTimeImmutable('-1 day'));
        $manager->persist($a2);

        $a3 = (new Article())
            ->setTitle('Sortie vélo dimanche 7h30')
            ->setContent('<p>Sortie vélo collective ce dimanche, RDV au Lac de la Ramée à <strong>7h30</strong>. ~80km, allure modérée. Pensez aux barres et à la chambre à air !</p>')
            ->setAuthor($admin)
            ->setPublishedAt(new \DateTimeImmutable('-3 hours'));
        $manager->persist($a3);

        // -- Events --
        $events = [
            ['title' => 'Triathlon de Toulouse', 'type' => EventType::Course, 'date' => '+45 days', 'location' => 'Toulouse'],
            ['title' => 'Stage Banyuls', 'type' => EventType::Stage, 'date' => '+30 days', 'location' => 'Banyuls-sur-Mer'],
            ['title' => 'Sortie longue VTT', 'type' => EventType::Entrainement, 'date' => '+5 days', 'location' => 'Lac de la Ramée'],
            ['title' => 'Repas de fin de saison', 'type' => EventType::Social, 'date' => '+90 days', 'location' => 'Restaurant Le Bistrot'],
            ['title' => 'Ironman Vichy', 'type' => EventType::Course, 'date' => '+120 days', 'location' => 'Vichy'],
        ];
        foreach ($events as $e) {
            $event = (new Event())
                ->setTitle($e['title'])
                ->setType($e['type'])
                ->setStartsAt(new \DateTimeImmutable($e['date']))
                ->setLocation($e['location']);
            $manager->persist($event);
        }

        // -- Static pages --
        $manager->persist((new StaticPage())
            ->setSlug('lieux-rdv')
            ->setTitle('Lieux de rendez-vous')
            ->setContent('<h2>Natation</h2><p>Piscine Léo Lagrange — mardi 19h, jeudi 19h, samedi 9h.</p><h2>Vélo</h2><p>Lac de la Ramée — dimanche 8h (été 7h30).</p><h2>Course à pied</h2><p>Stade des Argoulets — mercredi 18h30.</p>')
            ->setIsPublished(true));

        $manager->persist((new StaticPage())
            ->setSlug('bureau')
            ->setTitle('Le bureau')
            ->setContent('<h2>Président</h2><p>Jean-Marc Dupont — president@ttm-toulouse.fr</p><h2>Trésorier</h2><p>Sophie Martin</p><h2>Secrétaire</h2><p>Pierre Lambert</p>')
            ->setIsPublished(true));

        $manager->persist((new StaticPage())
            ->setSlug('partenaires')
            ->setTitle('Nos partenaires')
            ->setContent('<p>Le club remercie ses partenaires fidèles :</p><ul><li>Vélos Cyclos Toulouse</li><li>Sport2000 Blagnac</li><li>Mairie de Toulouse</li></ul>')
            ->setIsPublished(true));

        // -- Menu items --
        $menuItems = [
            ['label' => 'Actus', 'type' => MenuItemType::Feed, 'icon' => 'newspaper-outline', 'pos' => 1],
            ['label' => 'Entraînement', 'type' => MenuItemType::Training, 'icon' => 'fitness-outline', 'pos' => 2],
            ['label' => 'Calendrier', 'type' => MenuItemType::Calendar, 'icon' => 'calendar-outline', 'pos' => 3],
            ['label' => 'Lieux RDV', 'type' => MenuItemType::Page, 'target' => 'lieux-rdv', 'icon' => 'location-outline', 'pos' => 4],
            ['label' => 'Bureau', 'type' => MenuItemType::Page, 'target' => 'bureau', 'icon' => 'people-outline', 'pos' => 5],
            ['label' => 'Partenaires', 'type' => MenuItemType::Page, 'target' => 'partenaires', 'icon' => 'star-outline', 'pos' => 6],
        ];
        foreach ($menuItems as $m) {
            $item = (new MenuItem())
                ->setLabel($m['label'])
                ->setType($m['type'])
                ->setTarget($m['target'] ?? null)
                ->setIcon($m['icon'])
                ->setPosition($m['pos']);
            $manager->persist($item);
        }

        $manager->flush();
    }

    /**
     * @param list<Profile> $profiles
     */
    private function makeUser(
        string $numLicence,
        string $email,
        string $prenom,
        string $nom,
        string $role = 'user',
        array $profiles = [Profile::Senior],
        ?string $password = null,
    ): User {
        $u = new User();
        $u->setNumLicence($numLicence);
        $u->setEmail($email);
        $u->setPrenom($prenom);
        $u->setNom($nom);
        $u->setType(UserType::Adherent);
        $u->setRole($role);
        $u->setProfiles($profiles);
        $u->setStatutLicence('Actif');
        $u->setIsActive(true);
        if ($password !== null) {
            $u->setPassword($this->hasher->hashPassword($u, $password));
        }
        return $u;
    }
}
