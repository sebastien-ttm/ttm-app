<?php

namespace App\Controller\Admin;

use App\Entity\RaceProposal;
use App\Enum\RaceProposalStatus;
use App\Enum\RaceType;
use App\Service\RaceProposalModerator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Compétitions proposées par les adhérents (onglet Social côté mobile) : modération.
 *
 * Une proposition arrive « En attente de validation » : seul son auteur la voit dans l'appli. Un administrateur la
 * VALIDE (elle est publiée et les adhérents sont prévenus) ou la REFUSE (par exemple un événement de triathlon non
 * affilié à la FFTri). Une compétition validée peut être mise EN PAUSE (toujours visible, mais plus de nouvelle réponse)
 * puis REPRISE. Le motif d'un refus ou d'une pause se saisit dans « Message de l'administrateur » (Modifier), AVANT de
 * cliquer sur Refuser / Mettre en pause. Voir RaceProposalModerator.
 *
 * Pas de création depuis le backend — une proposition a toujours un auteur adhérent. L'auteur et les votes ne sont pas
 * modifiables. Modifier / supprimer : entraîneurs et plus ; valider, refuser, pause, reprise : administrateurs.
 */
class RaceProposalCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly RaceProposalModerator $moderation,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return RaceProposal::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Compétition proposée')
            ->setEntityLabelInPlural('Compétitions proposées')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['raceDate' => 'DESC'])
            ->setSearchFields(['name', 'author.nom', 'author.prenom']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('status', 'Statut')->setChoices([
            RaceProposalStatus::Pending->label() => RaceProposalStatus::Pending,
            RaceProposalStatus::Validated->label() => RaceProposalStatus::Validated,
            RaceProposalStatus::Paused->label() => RaceProposalStatus::Paused,
            RaceProposalStatus::Refused->label() => RaceProposalStatus::Refused,
        ]));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Compétition');
        // Statut de modération (affichage seulement : il change par les boutons Valider / Refuser / Mettre en pause / Reprendre).
        yield TextField::new('statusLabel', 'Statut')
            ->setSortable(false)
            ->hideOnForm()
            ->renderAsHtml()
            ->formatValue(static fn ($value, RaceProposal $race) => sprintf(
                '<span class="badge badge-%s">%s</span>',
                match ($race->getStatus()) {
                    RaceProposalStatus::Pending => 'warning',
                    RaceProposalStatus::Validated => 'success',
                    RaceProposalStatus::Paused => 'info',
                    RaceProposalStatus::Refused => 'danger',
                },
                htmlspecialchars($race->getStatus()->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ));
        yield DateField::new('raceDate', 'Début')
            ->setFormat('d MMM yyyy');
        yield DateField::new('raceEndDate', 'Fin')
            ->setFormat('d MMM yyyy')
            ->setRequired(false)
            ->setHelp('Dernier jour, pour une compétition sur plusieurs jours. Vide = une seule journée.');
        // EnumType : le formulaire manipule directement des RaceType
        // (même choix que boardRole dans UserCrudController).
        yield ChoiceField::new('type', 'Type')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class' => RaceType::class,
                'choice_label' => fn (RaceType $t) => $t->label(),
            ])
            ->setHelp('Triathlon : seuls les événements affiliés à la FFTri sont acceptés.')
            ->onlyOnForms();
        yield TextField::new('typeLabel', 'Type')
            ->setSortable(false)
            ->hideOnForm();
        yield AssociationField::new('author', 'Proposée par')
            ->hideOnForm();
        yield BooleanField::new('captain', 'Capitaine')
            ->renderAsSwitch(false)
            ->setHelp('L\'auteur se propose comme capitaine (organise l\'inscription groupée, le déplacement…).');
        yield DateField::new('registrationDeadline', 'Date limite de réponse')
            ->setFormat('d MMM yyyy')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Capitaine uniquement : passée, les adhérents ne peuvent plus changer leur réponse.');
        yield TextareaField::new('description', 'Description')
            ->setRequired(false)
            ->setNumOfRows(5)
            ->hideOnIndex();
        yield BooleanField::new('carpoolingEnabled', 'Covoiturage activé')
            ->renderAsSwitch(false)
            ->hideOnIndex();
        yield IntegerField::new('interestedCount', 'Intéressés')
            ->setSortable(false)
            ->hideOnForm();
        yield UrlField::new('url', 'Site')
            ->setRequired(false)
            ->hideOnIndex();
        yield TextareaField::new('moderationNote', 'Message de l\'administrateur')
            ->setRequired(false)
            ->setNumOfRows(2)
            ->hideOnIndex()
            ->setHelp(
                'Motif d\'un refus (montré à l\'auteur) ou d\'une mise en pause (montré à tous sur la fiche de la compétition). '
                .'À saisir et enregistrer AVANT de cliquer sur « Refuser » ou « Mettre en pause » ; effacé à la validation et à la reprise.'
            );
        yield DateTimeField::new('createdAt', 'Proposée le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnIndex()
            ->hideOnForm();
        yield DateTimeField::new('publishedAt', 'Publiée le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnIndex()
            ->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        $isAdmin = fn (): bool => $this->isGranted('ROLE_ADMIN');

        $validate = Action::new('validate', 'Valider', 'fa fa-check')
            ->linkToCrudAction('validate')
            ->setCssClass('btn btn-success')
            ->displayIf(fn (RaceProposal $r) => $isAdmin() && ($r->isPending() || $r->isRefused()));
        $refuse = Action::new('refuse', 'Refuser', 'fa fa-xmark')
            ->linkToCrudAction('refuse')
            ->setCssClass('btn btn-danger')
            ->displayIf(fn (RaceProposal $r) => $isAdmin() && $r->isPending());
        $pause = Action::new('pause', 'Mettre en pause', 'fa fa-pause')
            ->linkToCrudAction('pause')
            ->setCssClass('btn btn-warning')
            ->displayIf(fn (RaceProposal $r) => $isAdmin() && $r->getStatus() === RaceProposalStatus::Validated);
        $resume = Action::new('resume', 'Reprendre', 'fa fa-play')
            ->linkToCrudAction('resume')
            ->setCssClass('btn btn-success')
            ->displayIf(fn (RaceProposal $r) => $isAdmin() && $r->isPaused());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $validate)
            ->add(Crud::PAGE_INDEX, $pause)
            ->add(Crud::PAGE_INDEX, $resume)
            ->add(Crud::PAGE_DETAIL, $validate)
            ->add(Crud::PAGE_DETAIL, $refuse)
            ->add(Crud::PAGE_DETAIL, $pause)
            ->add(Crud::PAGE_DETAIL, $resume)
            ->disable(Action::NEW);
    }

    /** Publie la proposition : visible de tous, les adhérents sont prévenus. */
    public function validate(AdminContext $context): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $race = $this->currentRace($context);

        if ($this->moderation->validate($race)) {
            $this->addFlash('success', sprintf('« %s » est validée et publiée : les adhérents sont prévenus.', $race->getName()));
        } else {
            $this->addFlash('warning', 'Cette compétition ne peut pas être validée : elle l\'est déjà, ou elle est en pause (utilisez « Reprendre »).');
        }

        return $this->redirectToDetail($race);
    }

    /** Refuse une proposition en attente ; son auteur en est prévenu, avec le message saisi (facultatif). */
    public function refuse(AdminContext $context): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $race = $this->currentRace($context);

        if ($this->moderation->refuse($race, $race->getModerationNote())) {
            $this->addFlash('success', sprintf('« %s » est refusée : seul son auteur la voit, et il en est prévenu.', $race->getName()));
        } else {
            $this->addFlash('warning', 'Seule une proposition en attente de validation peut être refusée.');
        }

        return $this->redirectToDetail($race);
    }

    /** Suspend une compétition publiée : toujours visible, mais plus de nouvelle réponse. */
    public function pause(AdminContext $context): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $race = $this->currentRace($context);

        if ($this->moderation->pause($race, $race->getModerationNote())) {
            $this->addFlash('success', sprintf('« %s » est en pause : elle reste visible, mais les réponses sont suspendues.', $race->getName()));
        } else {
            $this->addFlash('warning', 'Seule une compétition validée peut être mise en pause.');
        }

        return $this->redirectToDetail($race);
    }

    /** Reprend une compétition en pause : les réponses sont de nouveau ouvertes. */
    public function resume(AdminContext $context): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $race = $this->currentRace($context);

        if ($this->moderation->resume($race)) {
            $this->addFlash('success', sprintf('« %s » est reprise : les réponses sont de nouveau ouvertes.', $race->getName()));
        } else {
            $this->addFlash('warning', 'Cette compétition n\'est pas en pause.');
        }

        return $this->redirectToDetail($race);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof RaceProposal) {
            $entityInstance->touchUpdatedAt();
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function currentRace(AdminContext $context): RaceProposal
    {
        /** @var RaceProposal $race */
        $race = $context->getEntity()->getInstance();

        return $race;
    }

    private function redirectToDetail(RaceProposal $race): RedirectResponse
    {
        return $this->redirect(
            $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($race->getId())
                ->generateUrl()
        );
    }
}
