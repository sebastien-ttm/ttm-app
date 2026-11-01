<?php

namespace App\Controller\Admin;

use App\Entity\PerfTestPace;
use App\Enum\PerfTest;
use App\Repository\PerfTestSessionRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Intervalles des 5 allures du club, par épreuve ET par saison : les entraîneurs en modifient les temps pour équilibrer
 * le nombre d'adhérents dans chaque allure (le niveau général change d'une année à l'autre). Une ligne par épreuve et par
 * saison ; une saison sans ligne reprend celle de la saison précédente. Pour démarrer une saison, « Récap des allures »
 * permet de copier les intervalles d'une autre saison, à ajuster ici ensuite.
 */
class PerfTestPaceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PerfTestPace::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Intervalles d\'allure')
            ->setEntityLabelInPlural('Intervalles d\'allure')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['seasonStartYear' => 'DESC', 'id' => 'ASC'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Le temps réalisé au test classe l\'adhérent dans une allure de 1 à 5. Chaque allure va jusqu\'à un temps maximal (inclus) ; '
                .'l\'allure 5 n\'a pas de borne. Les intervalles sont propres à chaque SAISON : réglez-les pour équilibrer le nombre d\'adhérents '
                .'dans chaque allure (le « Récap des allures » les compte). Une saison sans ligne reprend celle de la saison précédente. '
                .'Natation : un temps réalisé en bassin de 50 m est diminué de 10 secondes avant la comparaison. '
                .'Les changements s\'appliquent tout de suite au récap de la saison concernée.',
            );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('seasonStartYear', 'Saison')->setChoices(PerfTestPace::seasonChoices()));
    }

    /** Nouvelle ligne : saison en cours, 1500 m (intervalles d'origine à adapter). */
    public function createEntity(string $entityFqcn): PerfTestPace
    {
        return new PerfTestPace(PerfTest::Run1500, PerfTestSessionRepository::seasonStartYear(new \DateTimeImmutable('today')));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('seasonLabel', 'Saison')->onlyOnIndex();
        yield TextField::new('testLabel', 'Épreuve')->onlyOnIndex();
        yield TextField::new('intervalsText', 'Intervalles actuels')->onlyOnIndex();

        // L'épreuve et la saison se choisissent à la création ; ensuite elles s'affichent sans pouvoir être changées.
        $editing = $pageName === Crud::PAGE_EDIT;
        yield ChoiceField::new('test', 'Épreuve')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class' => PerfTest::class,
                'choice_label' => fn (PerfTest $t) => $t->icon().' '.$t->label(),
                'disabled' => $editing,
            ])
            ->onlyOnForms();
        yield ChoiceField::new('seasonStartYear', 'Saison')
            ->setChoices(PerfTestPace::seasonChoices())
            ->setFormTypeOption('disabled', $editing)
            ->setHelp('Saison d\'entraînement : du 1er septembre au 31 août (2026-2027 démarre le 1er septembre 2026).')
            ->onlyOnForms();

        foreach ([1, 2, 3, 4] as $n) {
            yield TextField::new('level'.$n.'MaxLabel', 'Allure '.$n.' : jusqu\'à (inclus)')
                ->onlyOnForms()
                ->setFormTypeOption('constraints', [
                    new NotBlank(message: 'Indiquez un temps (ex : 5:59).'),
                    new Regex(pattern: '/^\d{1,2}\s*[:\'’.]\s*\d{2}$/u', message: 'Format attendu : minutes et secondes, par exemple 5:59 ou 5\'59.'),
                ])
                ->setHelp(match ($n) {
                    1 => 'Temps maximal de l\'allure 1, au format minutes:secondes (ex : 5:59). Un temps plus rapide ou égal est en allure 1.',
                    4 => 'Temps maximal de l\'allure 4 (plus lent que celui de l\'allure 3). L\'allure 5 est sans limite : tout temps plus lent.',
                    default => 'Temps maximal de l\'allure '.$n.' : il doit être plus lent que celui de l\'allure '.($n - 1).'.',
                });
        }
    }
}
