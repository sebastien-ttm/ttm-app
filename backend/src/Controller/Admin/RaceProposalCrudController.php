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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compétitions proposées par les adhérents (onglet Social côté mobile) : modération des TRIATHLONS. Les autres types de
 * course sont publiés directement ; on ne les retrouve ici que pour les modifier ou les supprimer.
 *
 * Un triathlon proposé arrive « En attente de validation » : seul son auteur le voit dans l'appli, et les administrateurs
 * sont prévenus par notification et par e-mail. Un administrateur le VALIDE (il est publié, l'auteur et les adhérents sont
 * prévenus) ou le REFUSE (par exemple un événement non affilié à la FFTri) depuis un formulaire où il indique le motif :
 * l'auteur le reçoit par notification et par e-mail, et le lit sur sa proposition dans l'appli. Voir RaceProposalModerator.
 *
 * Pas de création depuis le backend — une proposition a toujours un auteur adhérent. L'auteur et les votes ne sont pas
 * modifiables. Modifier / supprimer : entraîneurs et plus ; valider et refuser : administrateurs.
 */
class RaceProposalCrudController extends AbstractCrudController
{
    /** Préfixe du jeton CSRF du formulaire de refus (suivi de l'identifiant de la proposition). */
    private const CSRF_REFUSE = 'race_refuse_';

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
            RaceProposalStatus::Refused->label() => RaceProposalStatus::Refused,
        ]));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Compétition');
        // Statut de modération (affichage seulement : il change par les boutons Valider / Refuser).
        yield TextField::new('statusLabel', 'Statut')
            ->setSortable(false)
            ->hideOnForm()
            ->renderAsHtml()
            ->formatValue(static fn ($value, RaceProposal $race) => sprintf(
                '<span class="badge badge-%s">%s</span>',
                match ($race->getStatus()) {
                    RaceProposalStatus::Pending => 'warning',
                    RaceProposalStatus::Validated => 'success',
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
            ->setHelp('Triathlon : validé par un administrateur avant publication, et seuls les événements affiliés à la FFTri sont acceptés. Les autres types sont publiés directement.')
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
        // Motif d'un refus, montré à l'auteur : il se saisit dans le formulaire du bouton « Refuser » (lecture seule ici).
        yield TextareaField::new('moderationNote', 'Motif du refus')
            ->hideOnIndex()
            ->hideOnForm();
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

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $validate)
            ->add(Crud::PAGE_INDEX, $refuse)
            ->add(Crud::PAGE_DETAIL, $validate)
            ->add(Crud::PAGE_DETAIL, $refuse)
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
            $this->addFlash('warning', 'Cette compétition est déjà validée.');
        }

        return $this->redirectToDetail($race);
    }

    /**
     * Refuse une proposition en attente. Formulaire en deux temps : la page (GET) demande le motif, l'envoi (POST, avec
     * jeton CSRF) refuse. L'auteur est prévenu — notification et e-mail — avec ce motif, qui s'affiche aussi sur sa
     * proposition dans l'appli.
     */
    public function refuse(AdminContext $context, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $race = $this->currentRace($context);

        if (!$race->isPending()) {
            $this->addFlash('warning', 'Seule une proposition en attente de validation peut être refusée.');

            return $this->redirectToDetail($race);
        }

        $note = trim((string) $request->request->get('note', ''));
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_REFUSE.$race->getId(), (string) $request->request->get('_token'))) {
                $error = 'Le formulaire a expiré : réessayez.';
            } elseif (mb_strlen($note) > RaceProposal::MODERATION_NOTE_MAX) {
                $error = sprintf('Le motif est trop long (%d caractères au plus).', RaceProposal::MODERATION_NOTE_MAX);
            } else {
                if ($this->moderation->refuse($race, $note)) {
                    $this->addFlash('success', sprintf('« %s » est refusée : seul son auteur la voit, et il en est prévenu avec le motif.', $race->getName()));
                } else {
                    $this->addFlash('warning', 'Seule une proposition en attente de validation peut être refusée.');
                }

                return $this->redirectToDetail($race);
            }
        }

        return $this->render('admin/race_refuse.html.twig', [
            'race' => $race,
            'note' => $note,
            'error' => $error,
            'csrfId' => self::CSRF_REFUSE.$race->getId(),
            'noteMax' => RaceProposal::MODERATION_NOTE_MAX,
        ]);
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
