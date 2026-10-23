<?php

namespace App\Controller\Admin;

use App\Entity\PerfTestPace;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Intervalles des 5 allures du club, par épreuve : les entraîneurs en modifient les temps. Une ligne par épreuve
 * (course, natation, vélo), créée par la migration : ni création ni suppression ici.
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
            ->setDefaultSort(['id' => 'ASC'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Le temps réalisé au test classe l\'adhérent dans une allure de 1 à 5. Chaque allure va jusqu\'à un temps maximal (inclus) ; '
                .'l\'allure 5 n\'a pas de borne. Natation : un temps réalisé en bassin de 50 m est diminué de 10 secondes avant la comparaison. '
                .'Les changements s\'appliquent tout de suite, au récap de toutes les saisons.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        // Trois lignes fixes (une par épreuve), créées par la migration.
        return parent::configureActions($actions)->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('testLabel', 'Épreuve')->hideOnForm();
        yield TextField::new('intervalsText', 'Intervalles actuels')->hideOnForm();

        // Édition : l'épreuve s'affiche sans pouvoir être changée, puis les 4 temps maximaux.
        yield TextField::new('testLabel', 'Épreuve')
            ->setFormTypeOption('disabled', true)
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
