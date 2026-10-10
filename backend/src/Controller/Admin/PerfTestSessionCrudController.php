<?php

namespace App\Controller\Admin;

use App\Entity\PerfTestSession;
use App\Entity\User;
use App\Enum\PerfTest;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Séances de test chronométré. Après création, l'entraîneur est envoyé
 * sur la feuille de saisie des temps (PerfTestResultController).
 */
class PerfTestSessionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PerfTestSession::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prise de temps')
            ->setEntityLabelInPlural('Tests chronométrés')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['date' => 'DESC', 'id' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Créez une prise de temps (épreuve, période — début et fin —, bassin pour la natation), puis « Saisir les temps ». Les temps déclarés par les adhérents s\'ajoutent par défaut à la plus récente. Supprimer une prise de temps supprime ses temps.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $enterTimes = Action::new('enterTimes', 'Saisir les temps', 'fa fa-stopwatch')
            ->linkToRoute('admin_perf_test_sheet', fn (PerfTestSession $s) => ['id' => $s->getId()])
            ->displayIf(fn (PerfTestSession $s) => $s->getId() !== null);

        $importTimes = Action::new('importTimes', 'Importer depuis Excel', 'fa fa-paste')
            ->linkToRoute('admin_perf_test_import', fn (PerfTestSession $s) => ['id' => $s->getId()])
            ->displayIf(fn (PerfTestSession $s) => $s->getId() !== null);

        return $actions
            ->add(Crud::PAGE_INDEX, $enterTimes)
            ->add(Crud::PAGE_INDEX, $importTimes)
            ->add(Crud::PAGE_EDIT, $enterTimes)
            ->reorder(Crud::PAGE_INDEX, ['enterTimes', 'importTimes', Action::EDIT, Action::DELETE]);
    }

    public function configureAssets(Assets $assets): Assets
    {
        // Le choix du bassin n'apparaît que pour le 400 m natation.
        return $assets->addHtmlContentToBody(sprintf(<<<'HTML'
            <script>
            (function () {
                const radios = document.querySelectorAll('input[type=radio][name$="[test]"]');
                const pool = document.querySelector('.js-perf-pool-length');
                if (!radios.length || !pool) return;
                const update = () => {
                    const checked = document.querySelector('input[type=radio][name$="[test]"]:checked');
                    pool.hidden = !checked || checked.value !== %s;
                };
                radios.forEach(r => r.addEventListener('change', update));
                update();
            })();
            </script>
            HTML, json_encode(PerfTest::Swim400->value)));
    }

    public function createEntity(string $entityFqcn): PerfTestSession
    {
        $session = new PerfTestSession();
        $user = $this->getUser();
        if ($user instanceof User) {
            $session->setCreatedBy($user);
        }
        return $session;
    }

    public function configureFields(string $pageName): iterable
    {
        // EnumType : le formulaire manipule directement des PerfTest
        // (voir le même choix pour boardRole dans UserCrudController).
        yield ChoiceField::new('test', 'Épreuve')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class' => PerfTest::class,
                'expanded' => true,
                'choice_label' => fn (PerfTest $t) => $t->icon().' '.$t->label(),
            ])
            ->onlyOnForms();
        yield TextField::new('testLabel', 'Épreuve')->onlyOnIndex();
        yield ChoiceField::new('poolLength', 'Bassin')
            ->setChoices(['25 m' => 25, '50 m' => 50])
            ->renderExpanded()
            ->setRequired(false)
            ->setHelp('Les temps ne sont comparés qu\'entre séances du même bassin.')
            ->addCssClass('js-perf-pool-length')
            ->onlyOnForms();
        yield DateField::new('date', 'Début de la période')
            ->setFormat('dd/MM/yyyy')
            ->onlyOnForms();
        yield DateField::new('endDate', 'Fin de la période')
            ->setFormat('dd/MM/yyyy')
            ->setRequired(false)
            ->setHelp('Facultatif : à renseigner si la prise de temps s\'étale sur plusieurs jours (ex : 2 soirs). Laissez vide pour un seul jour. Les temps saisis valent pour toute la période.')
            ->onlyOnForms();
        yield TextField::new('datesLabel', 'Période')
            ->setSortable(false)
            ->hideOnForm();
        yield TextField::new('notes', 'Notes')
            ->setRequired(false)
            ->setHelp('Optionnel : lieu, groupe testé, conditions (vent, chaleur…).');
        yield IntegerField::new('resultsCount', 'Temps saisis')->onlyOnIndex();
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        // Après création : directement sur la feuille de saisie.
        $session = $context->getEntity()->getInstance();
        if ($action === Action::NEW && $session instanceof PerfTestSession && $session->getId() !== null) {
            return $this->redirectToRoute('admin_perf_test_sheet', ['id' => $session->getId()]);
        }
        return parent::getRedirectResponseAfterSave($context, $action);
    }
}
