<?php

namespace App\Controller\Admin;

use App\Entity\BoardMeeting;
use App\Entity\User;
use App\Service\Board\BoardMeetingNotifier;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Réunions du comité directeur (administrateurs) : programmer une réunion (les membres du CoDir sont notifiés),
 * la clore une fois tenue. Les réponses se saisissent sur la page « En séance ».
 */
class BoardMeetingCrudController extends AbstractCrudController
{
    public function __construct(private readonly BoardMeetingNotifier $notifier)
    {
    }

    public static function getEntityFqcn(): string
    {
        return BoardMeeting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réunion du CoDir')
            ->setEntityLabelInPlural('Réunions du CoDir')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['scheduledAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('scheduledAt', 'Date et heure')->setFormat('EEEE d MMMM yyyy HH:mm');
        yield TextField::new('location', 'Lieu')->setRequired(false);
        yield TextareaField::new('notes', 'Informations (invités, ordre de passage…)')
            ->setRequired(false)
            ->setNumOfRows(3)
            ->hideOnIndex();
        yield IntegerField::new('itemCount', 'Points')->hideOnForm()->setSortable(false);
        yield IntegerField::new('answeredCount', 'Traités')->hideOnForm()->setSortable(false);
        yield BooleanField::new('closed', 'Réunion close')
            ->renderAsSwitch(false)
            ->hideWhenCreating()
            ->setHelp('Close : plus aucun point ne peut être ajouté depuis l\'appli ; l\'ordre du jour devient le compte rendu.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $session = Action::new('session', 'En séance', 'fa fa-gavel')
            ->linkToRoute('admin_board_session', static fn (BoardMeeting $m) => ['meeting' => $m->getId()]);
        $export = Action::new('exportCsv', 'Export CSV', 'fa fa-file-csv')
            ->linkToRoute('admin_board_export', static fn (BoardMeeting $m) => ['id' => $m->getId()]);

        return $actions
            ->add(Crud::PAGE_INDEX, $session)
            ->add(Crud::PAGE_INDEX, $export)
            ->add(Crud::PAGE_DETAIL, $session)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        if ($entityInstance instanceof BoardMeeting) {
            $user = $this->getUser();
            $this->notifier->notifyScheduled($entityInstance, $user instanceof User ? $user : null);
        }
    }
}
