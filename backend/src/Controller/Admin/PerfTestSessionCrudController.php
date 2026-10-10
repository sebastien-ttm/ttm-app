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
            ->setEntityLabelInSingular('Séance de test')
            ->setEntityLabelInPlural('Tests chronométrés')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['date' => 'DESC', 'id' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Créez une séance (épreuve, date — plusieurs si elle s\'étale sur plusieurs jours —, bassin pour la natation), puis « Saisir les temps ». Supprimer une séance supprime ses temps.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $enterTimes = Action::new('enterTimes', 'Saisir les temps', 'fa fa-stopwatch')
            ->linkToRoute('admin_perf_test_sheet', fn (PerfTestSession $s) => ['id' => $s->getId()])
            ->displayIf(fn (PerfTestSession $s) => $s->getId() !== null);

        return $actions
            ->add(Crud::PAGE_INDEX, $enterTimes)
            ->add(Crud::PAGE_EDIT, $enterTimes)
            ->reorder(Crud::PAGE_INDEX, ['enterTimes', Action::EDIT, Action::DELETE]);
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
        yield DateField::new('date', 'Date')
            ->setFormat('dd/MM/yyyy')
            ->onlyOnForms();
        yield TextField::new('extraDatesText', 'Autres dates')
            ->setRequired(false)
            ->setHelp('Si la séance s\'étale sur plusieurs jours (ex : 2 soirs), ajoutez les autres dates séparées par des virgules : 14/03/2026, 21/03/2026. Les temps saisis valent pour l\'ensemble de la séance.')
            ->onlyOnForms();
        yield TextField::new('datesLabel', 'Date(s)')
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
